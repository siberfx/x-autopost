<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Livewire\ConnectionCard;
use Siberfx\XAutopost\Livewire\ShareButton;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', null, name: 'Ada Lovelace',
        connectedAt: CarbonImmutable::now()));
});

it('registers the connection card by name', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $this->blade('<livewire:x-autopost.connection />')->assertSee('Ada Lovelace');
});

it('disconnects from the card', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response('', 200), '*' => Http::response('down', 503)]);

    Livewire::test(ConnectionCard::class)
        ->assertSee('Ada Lovelace')
        ->assertSeeHtml('wire:click="disconnect"')
        ->call('disconnect')
        ->assertSee('X disconnected.')
        ->assertSee('Not connected');

    expect(app(TokenStore::class)->get())->toBeNull();
})->with(['tailwind', 'bootstrap']);

it('shares from the button and shows the result', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '11']], 201)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->assertSeeHtml('wire:click="share"')
        ->call('share')
        ->assertSee('Shared on X.')
        ->assertSee('Share again')
        ->assertDispatched('x-autopost-shared', id: '11');

    expect(XSharedPost::query()->for($post)->posted()->count())->toBe(1);
})->with(['tailwind', 'bootstrap']);

it('shows X errors on the button', function () {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['message' => 'Rate limited'], 429)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertSee('X rejected the request: Rate limited');
});

it('refuses to change which record the button shares', function () {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableId', '999'))
        ->toThrow(Exception::class, 'locked');
    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableType', GenericUser::class))
        ->toThrow(Exception::class, 'locked');
});

it('shows an invalid post URL on the button instead of failing', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertOk()
        ->assertSee('X posts need an absolute http(s) URL');
    Http::assertNothingSent();
});
