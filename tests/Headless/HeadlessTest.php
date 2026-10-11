<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\HeadlessTestCase;

uses(HeadlessTestCase::class);

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('registers no views, Blade or Livewire components', function () {
    expect(View::getFinder()->getHints())->not->toHaveKey('x-autopost')
        ->and(app('blade.compiler')->getClassComponentNamespaces())->not->toHaveKey('x-autopost');

    if (class_exists(Livewire\Livewire::class)) {
        expect(fn () => app('livewire.factory')->resolveComponentClass('x-autopost.connection'))->toThrow(Exception::class);
    }
});

it('keeps the routes, the facade and the commands', function () {
    expect(app('router')->has('x-autopost.redirect'))->toBeTrue()
        ->and(app('router')->has('x-autopost.share'))->toBeTrue();

    $this->artisan('x:status')->assertSuccessful();
});

it('answers a form share with JSON instead of a redirect', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '4']], 201)]);
    $url = URL::signedRoute('x-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertCreated()
        ->assertJsonPath('message', 'Shared on X.')
        ->assertJsonPath('data.post_id', '4');
});

it('answers a form disconnect with JSON', function () {
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/x/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('installs without asking for a theme', function () {
    $envDir = sys_get_temp_dir().'/x-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('x:install')
            ->expectsOutputToContain('X_UI=false')
            ->expectsOutputToContain("route('x-autopost.redirect')")
            ->doesntExpectOutputToContain('Tailwind')
            ->assertSuccessful();

        expect(File::get($envDir.'/.env'))->toBe("X_UI=false\n");
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('x-autopost.php'));
        foreach (File::glob(database_path('migrations/*x_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
