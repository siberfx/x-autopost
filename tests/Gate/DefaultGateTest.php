<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Livewire\ConnectionCard;
use Siberfx\XAutopost\Livewire\ShareButton;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\DefaultGateTestCase;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;

uses(DefaultGateTestCase::class);

beforeEach(function () {
    Http::fake();
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    app(TokenStore::class)->put(new StoredConnection('member-token', null, name: 'Ada Lovelace',
        connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('defines the gate and denies everyone by default', function () {
    expect(Gate::has('manage-x-autopost'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('manage-x-autopost'))->toBeFalse()
        ->and(Gate::allows('manage-x-autopost'))->toBeFalse();
});

it('refuses every endpoint to a logged-in user the gate does not allow', function () {
    $this->actingAs($this->user)->getJson('/x/redirect')->assertForbidden();
    $this->actingAs($this->user)->getJson('/x/callback?code=c&state=s')->assertForbidden();
    $this->actingAs($this->user)->getJson('/x/connection')->assertForbidden();
    $this->actingAs($this->user)->deleteJson('/x/connection')->assertForbidden();
    $this->actingAs($this->user)->postJson("/x/share/post/{$this->post->id}")->assertForbidden();
    $this->actingAs($this->user)
        ->postJson(URL::signedRoute('x-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]))
        ->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(XSharedPost::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses the Livewire actions', function () {
    $this->actingAs($this->user);

    Livewire::test(ConnectionCard::class)->call('disconnect')->assertForbidden();
    Livewire::test(ShareButton::class, ['model' => $this->post])->call('share')->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(XSharedPost::query()->count())->toBe(0);
    // Rendering the card may ask X for the status; nothing is revoked or posted.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'revoke') || str_contains($request->url(), '2/tweets'));
});

it('renders the share button disabled with the reason', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);

    $this->actingAs($this->user)->blade('<x-x-autopost::share-button :model="$post" />', ['post' => $this->post])
        ->assertSee('You are not allowed to manage X.')
        ->assertSeeHtml('disabled');
})->with(['tailwind', 'bootstrap']);

it('shows the connection read-only', function (string $theme, bool $livewire) {
    config(['x-autopost.ui.theme' => $theme]);
    Http::fake(['*' => Http::response('down', 503)]);
    $this->actingAs($this->user);

    $view = $livewire
        ? Livewire::test(ConnectionCard::class)
        : $this->blade('<x-x-autopost::connection />');

    $view->assertSee('Ada Lovelace')
        ->assertDontSee('Reconnect')
        ->assertDontSee('Disconnect')
        ->assertDontSee('Connect X');
})->with(['tailwind', 'bootstrap'])->with(['blade' => false, 'livewire' => true]);

it('hides the connect button when not connected', function () {
    app(TokenStore::class)->forget();

    $this->actingAs($this->user)->blade('<x-x-autopost::connection />')
        ->assertSee('Not connected')
        ->assertDontSee('Connect X');
});
