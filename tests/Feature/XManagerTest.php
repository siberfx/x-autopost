<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\Connection;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Events\Connected;
use Siberfx\XAutopost\Events\Disconnected;
use Siberfx\XAutopost\Events\Shared;
use Siberfx\XAutopost\Exceptions\NotConnected;
use Siberfx\XAutopost\Exceptions\NotShareable;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Facades\X;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\XManager;

const X_REVOKE = 'https://api.x.com/2/oauth2/revoke';
const X_TOKEN = 'https://api.x.com/2/oauth2/token';
const X_ME = 'https://api.x.com/2/users/me';
const X_TWEETS = 'https://api.x.com/2/tweets';

function connectForTest(string $token = 'member-token', ?string $refresh = 'refresh-1'): void
{
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: $token,
        refreshToken: $refresh,
        username: 'stored',
        name: 'Stored Name',
        scopes: ['tweet.write', 'offline.access'],
        connectedAt: CarbonImmutable::now()->subDays(10),
    ));
}

/** The manager remembers the stored connection per request; a token written behind its back needs a new one. */
function freshRequest(): void
{
    app()->forgetScopedInstances();
    X::clearResolvedInstance(XManager::class);
}

function publishedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => PostStatus::Published]);
}

/** A cache store like Laravel 13's defaults: values are serialized and no classes may be unserialized. */
function useSerializingCache(): void
{
    config([
        'cache.serializable_classes' => false,
        'cache.stores.serializing' => ['driver' => 'array', 'serialize' => true],
        'cache.default' => 'serializing',
    ]);
}

it('reads the stored connection once per request', function () {
    connectForTest();
    $x = app(XManager::class);
    DB::enableQueryLog();

    foreach (range(1, 5) as $ignored) {
        expect($x->isConnected())->toBeTrue();
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('forgets the remembered connection when it connects or disconnects', function () {
    Http::fake([
        X_REVOKE => Http::response('', 200),
        X_TOKEN => Http::response(['access_token' => 'new-token', 'refresh_token' => 'r']),
        X_ME.'*' => Http::response(['data' => ['username' => 'grace', 'name' => 'Grace Hopper']]),
    ]);
    $x = app(XManager::class);
    expect($x->isConnected())->toBeFalse();

    $x->completeConnection('code', 'verifier');
    expect($x->isConnected())->toBeTrue();

    $x->disconnect();
    expect($x->isConnected())->toBeFalse();
});

it('reports not connected without calling X', function () {
    Http::fake();

    expect(X::isConnected())->toBeFalse()
        ->and(X::connection()->connected)->toBeFalse()
        ->and(X::connection()->toArray())->toBe([
            'connected' => false, 'status' => null, 'name' => null, 'username' => null, 'picture' => null,
            'scopes' => [], 'connected_at' => null,
        ]);

    Http::assertNothingSent();
});

it('describes an active connection from the account lookup', function () {
    connectForTest();
    Http::fake([X_ME.'*' => Http::response(['data' => [
        'id' => '42', 'name' => 'Ada Lovelace', 'username' => 'ada', 'profile_image_url' => 'https://pbs.twimg.com/a.jpg',
    ]])]);

    $connection = X::connection();

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->username)->toBe('ada')
        ->and($connection->picture)->toBe('https://pbs.twimg.com/a.jpg')
        ->and($connection->scopes)->toBe(['tweet.write', 'offline.access'])
        ->and($connection->toArray())->not->toHaveKey('access_token')
        ->and($connection->toArray())->not->toHaveKey('refresh_token');
});

it('caches the status per token', function () {
    connectForTest();
    Http::fake([X_ME.'*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']])]);

    X::connection();
    X::connection();

    Http::assertSentCount(1);
});

it('caches the status as plain data, so it survives a store that refuses to unserialize classes', function () {
    useSerializingCache();
    connectForTest();
    Http::fake([X_ME.'*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']])]);

    $first = X::connection();
    $second = X::connection();

    expect($first)->toBeInstanceOf(Connection::class)
        ->and($second)->toBeInstanceOf(Connection::class)
        ->and($second->toArray())->toBe($first->toArray());
    Http::assertSentCount(1);
});

it('treats a cached value that is not plain data as a miss', function () {
    useSerializingCache();
    connectForTest();
    Http::fake([X_ME.'*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']])]);
    // What an older release cached: an object, which comes back as __PHP_Incomplete_Class.
    Cache::put('x-autopost:status:'.hash('sha256', 'member-token'), Connection::disconnected(), 300);

    expect(X::connection()->status)->toBe('active');
});

it('round-trips a connection through its cache form', function () {
    $connection = new Connection(true, 'active', 'Ada', 'ada', 'https://pic', ['tweet.write'],
        CarbonImmutable::createFromTimestamp(1790000000));

    expect(Connection::fromCache($connection->toCache())->toArray())->toBe($connection->toArray())
        ->and(Connection::fromCache(Connection::disconnected()->toCache())->connected)->toBeFalse();
});

it('says unknown with the stored profile when X is unreachable', function () {
    connectForTest();
    Http::fake(['*' => Http::response('down', 503)]);

    $connection = X::connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Stored Name')
        ->and($connection->username)->toBe('stored');
});

it('refreshes an expired access token, stores the rotated pair and reports active', function () {
    connectForTest('old-access', 'old-refresh');
    Http::fake([
        X_ME.'*' => Http::sequence()
            ->push(['title' => 'Unauthorized'], 401)
            ->push(['data' => ['username' => 'ada', 'name' => 'Ada']]),
        X_TOKEN => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh']),
    ]);

    expect(X::connection()->status)->toBe('active');

    $stored = app(TokenStore::class)->get();
    expect($stored?->accessToken)->toBe('new-access')
        ->and($stored?->refreshToken)->toBe('new-refresh');
    Http::assertSent(fn ($request) => $request->url() === X_TOKEN && $request['refresh_token'] === 'old-refresh');
});

it('retries a share once after refreshing a 401', function () {
    Event::fake([Shared::class]);
    connectForTest('old-access', 'old-refresh');
    Http::fake([
        X_TWEETS => Http::sequence()
            ->push(['title' => 'Unauthorized'], 401)
            ->push(['data' => ['id' => '99']], 201),
        X_TOKEN => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh']),
    ]);

    $record = X::share(publishedPost());

    expect($record->post_id)->toBe('99')
        ->and(app(TokenStore::class)->get()?->accessToken)->toBe('new-access');
    Http::assertSentCount(3);
});

it('marks the connection expired when the refresh is refused too', function () {
    connectForTest('old-access', 'old-refresh');
    Http::fake([
        X_TWEETS => Http::response(['title' => 'Unauthorized'], 401),
        X_TOKEN => Http::response(['error' => 'invalid_request'], 400),
        X_ME.'*' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    expect(fn () => X::share(publishedPost()))->toThrow(XRequestFailed::class);
    expect(X::connection()->status)->toBe('expired')
        ->and(app(TokenStore::class)->get()?->accessToken)->toBe('old-access')
        ->and(XSharedPost::query()->count())->toBe(0);
});

it('does not try to refresh without a refresh token', function () {
    connectForTest('old-access', null);
    Http::fake([X_TWEETS => Http::response(['title' => 'Unauthorized'], 401)]);

    expect(fn () => X::share(publishedPost()))->toThrow(XRequestFailed::class);
    Http::assertSentCount(1);
});

it('shares a live model, records the post and fires Shared', function () {
    Event::fake([Shared::class]);
    connectForTest();
    Http::fake([X_TWEETS => Http::response(['data' => ['id' => '1900000000000000007']], 201)]);
    $post = publishedPost();

    $record = X::share($post);

    expect($record->post_id)->toBe('1900000000000000007')
        ->and($record->status)->toBe(XSharedPost::STATUS_POSTED)
        ->and($record->trigger)->toBe(XSharedPost::TRIGGER_MANUAL)
        ->and($record->shareable_type)->toBe('post')
        ->and($record->posted_at)->not->toBeNull()
        ->and(X::wasPosted($post))->toBeTrue();
    Event::assertDispatched(Shared::class, fn (Shared $e) => $e->post->is($record) && $e->shareable->is($post));
});

it('records a post X accepted without returning its id', function () {
    connectForTest();
    Http::fake([X_TWEETS => Http::response(['data' => []], 201)]);
    $post = publishedPost();

    $record = X::share($post);

    expect($record->status)->toBe(XSharedPost::STATUS_POSTED)
        ->and($record->post_id)->toBeNull()
        ->and(X::wasPosted($post))->toBeTrue();
});

it('allows sharing the same model again', function () {
    connectForTest();
    Http::fake([X_TWEETS => Http::sequence()
        ->push(['data' => ['id' => '1']], 201)
        ->push(['data' => ['id' => '2']], 201)]);
    $post = publishedPost();

    X::share($post);
    X::share($post);

    expect(XSharedPost::query()->for($post)->posted()->pluck('post_id')->all())->toBe(['1', '2']);
});

it('refuses to share when not connected or not live', function () {
    Http::fake();

    expect(fn () => X::share(publishedPost()))->toThrow(NotConnected::class);

    connectForTest();
    freshRequest();
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    expect(fn () => X::share($draft))->toThrow(NotShareable::class);
    Http::assertNothingSent();
});

it('completes a connection from an authorization code', function () {
    Event::fake([Connected::class]);
    Http::fake([
        X_TOKEN => Http::response(['access_token' => 'new-token', 'refresh_token' => 'r-1']),
        X_ME.'*' => Http::response(['data' => ['username' => 'grace', 'name' => 'Grace Hopper']]),
    ]);

    $connection = X::completeConnection('code', 'verifier');

    expect($connection->status)->toBe('active')
        ->and($connection->username)->toBe('grace')
        ->and(app(TokenStore::class)->get()?->accessToken)->toBe('new-token')
        ->and(app(TokenStore::class)->get()?->refreshToken)->toBe('r-1');
    Event::assertDispatched(Connected::class, fn (Connected $e) => $e->connection->username === 'grace');
});

it('disconnects, revoking at X', function (int $revokeStatus, bool $revoked) {
    Event::fake([Disconnected::class]);
    connectForTest();
    Http::fake([X_REVOKE => Http::response('', $revokeStatus)]);

    expect(X::disconnect())->toBe($revoked)
        ->and(X::isConnected())->toBeFalse();
    Event::assertDispatched(Disconnected::class, fn (Disconnected $e) => $e->revoked === $revoked);
})->with([
    'revoked' => [200, true],
    'refused, still forgotten locally' => [500, false],
]);
