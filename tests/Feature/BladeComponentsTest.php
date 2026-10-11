<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;

function uiConnect(string $name = 'Ada Lovelace', bool $offline = true): void
{
    app(TokenStore::class)->put(new StoredConnection(
        'member-token', null, username: 'ada', name: $name,

        connectedAt: CarbonImmutable::parse('2026-10-02'),
    ));
    if ($offline) {
        Http::fake(['*' => Http::response('down', 503)]); // status "unknown" → stored profile, no network
    }
}

dataset('themes', ['tailwind', 'bootstrap']);

it('shows Connect when not connected', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);

    $this->blade('<x-x-autopost::connection />')
        ->assertSee('Not connected')
        ->assertSee(route('x-autopost.redirect'), false)
        ->assertDontSee('Disconnect');
})->with('themes');

it('shows the connected member with Reconnect and Disconnect', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    uiConnect();

    $this->blade('<x-x-autopost::connection />')
        ->assertSee('Ada Lovelace')
        ->assertSee('@ada')
        ->assertSee('Status unknown')
        ->assertSee('Reconnect')
        ->assertSee('Disconnect')
        ->assertSee(route('x-autopost.connection.destroy'), false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertDontSee('member-token');
})->with('themes');

it('shows the callback result, escaped', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    request()->query->replace(['x' => 'error', 'reason' => '<script>alert(1)</script>']);

    $this->blade('<x-x-autopost::connection />')
        ->assertSee('X refused the connection:')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
})->with('themes');

it('renders an enabled share button with a signed action', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    $this->blade('<x-x-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee('Share on X')
        ->assertSee('signature=', false)
        ->assertDontSee('disabled title=', false);
})->with('themes');

it('disables the share button with a reason', function (string $theme, Closure $arrange, string $reason) {
    config(['x-autopost.ui.theme' => $theme]);
    $post = $arrange();

    $this->blade('<x-x-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee($reason)
        ->assertSee('disabled title=', false);
})->with('themes')->with([
    'not connected' => [fn () => Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published])), 'Connect X first.'],
    'draft' => [function () {
        uiConnect();

        return Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);
    }, 'Only live items can be shared.'],
]);

it('offers a custom label with the last shared date', function () {
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    XSharedPost::query()->create(['shareable_type' => 'post', 'shareable_id' => $post->id, 'post_id' => '1',
        'status' => XSharedPost::STATUS_POSTED, 'trigger' => XSharedPost::TRIGGER_MANUAL, 'posted_at' => CarbonImmutable::parse('2026-09-30')]);

    $this->blade('<x-x-autopost::share-button :model="$post" label="Post it" />', ['post' => $post])
        ->assertSee('Post it')
        ->assertSee('30 Sep 2026');

    $this->blade('<x-x-autopost::share-button :model="$post" />', ['post' => $post])->assertSee('Share again');
});

it('renders the session flash', function (string $theme) {
    config(['x-autopost.ui.theme' => $theme]);
    session()->flash('x-autopost.flash', ['type' => 'success', 'message' => 'Shared on X.']);

    $this->blade('<x-x-autopost::flash />')->assertSee('Shared on X.');
})->with('themes');
