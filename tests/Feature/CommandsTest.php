<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Events\TokenExpired;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;

function connectCommandTest(): void
{
    app(TokenStore::class)->put(new StoredConnection(
        'member-token', null, name: 'Ada',

        connectedAt: CarbonImmutable::now(),
    ));
}

it('shows the status without the token', function () {
    connectCommandTest();
    Http::fake(['*' => Http::response('down', 503)]);

    $this->artisan('x:status')
        ->expectsOutputToContain('Ada')
        ->expectsOutputToContain('unknown')
        ->doesntExpectOutputToContain('member-token')
        ->assertSuccessful();
});

it('says when not connected', function () {
    $this->artisan('x:status')->expectsOutputToContain('Not connected')->assertSuccessful();
});

it('disconnects after confirming', function () {
    connectCommandTest();
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::response('', 200)]);

    $this->artisan('x:disconnect')
        ->expectsConfirmation('Disconnect X and revoke the token?', 'yes')
        ->expectsOutputToContain('Disconnected')
        ->assertSuccessful();

    expect(app(TokenStore::class)->get())->toBeNull();
});

it('keeps the connection when the confirmation is declined', function () {
    connectCommandTest();

    $this->artisan('x:disconnect')
        ->expectsConfirmation('Disconnect X and revoke the token?', 'no')
        ->assertFailed();

    expect(app(TokenStore::class)->get())->not->toBeNull();
});

it('shares a model by alias or class, refusing a repeat without --force', function () {
    connectCommandTest();
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '9']], 201)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    $this->artisan('x:share', ['model' => 'post', 'id' => $post->id])
        ->expectsOutputToContain('9')->assertSuccessful();
    $this->artisan('x:share', ['model' => Post::class, 'id' => $post->id])
        ->expectsOutputToContain('already')->assertFailed();
    $this->artisan('x:share', ['model' => 'post', 'id' => $post->id, '--force' => true])
        ->assertSuccessful();

    expect(XSharedPost::query()->where('trigger', XSharedPost::TRIGGER_CONSOLE)->count())->toBe(2);
});

it('fails clearly for an unknown model or record', function () {
    connectCommandTest();

    $this->artisan('x:share', ['model' => 'nope', 'id' => 1])->expectsOutputToContain('not a shareable model')->assertFailed();
    $this->artisan('x:share', ['model' => 'post', 'id' => 999])->expectsOutputToContain('not found')->assertFailed();
});

it('fires TokenExpired when X no longer accepts the connection', function () {
    Event::fake([TokenExpired::class]);
    connectCommandTest();
    Http::fake(['*' => Http::response(['title' => 'Unauthorized'], 401)]);

    $this->artisan('x:check-token')->assertSuccessful();

    Event::assertDispatched(TokenExpired::class, fn (TokenExpired $e) => $e->connection->status === 'expired');
});

it('does not fire TokenExpired while the connection is active', function () {
    Event::fake([TokenExpired::class]);
    connectCommandTest();
    Http::fake(['*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']])]);

    $this->artisan('x:check-token')->assertSuccessful();

    Event::assertNotDispatched(TokenExpired::class);
});
