<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Facades\X;
use Siberfx\XAutopost\Tests\Fixtures\ArrayTokenStore;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;

beforeEach(function () {
    ArrayTokenStore::$connection = null;
    config(['x-autopost.token_store' => ArrayTokenStore::class]);
    app()->forgetScopedInstances();
});

it('connects, shares and disconnects through a custom store', function () {
    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 100]),
        'https://api.x.com/2/users/me*' => Http::response(['data' => ['username' => 'ada', 'name' => 'Ada']]),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '1']], 201),
        'https://api.x.com/2/oauth2/revoke' => Http::response('', 200),
    ]);

    X::completeConnection('code', 'verifier');
    expect(ArrayTokenStore::$connection?->accessToken)->toBe('tok')
        ->and(DB::table('x_connections')->count())->toBe(0);

    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    expect(X::share($post)->post_id)->toBe('1');

    X::disconnect();
    expect(ArrayTokenStore::$connection)->toBeNull();
});
