<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Posts\PostsClient;
use Siberfx\XAutopost\Posts\XPost;

const TWEETS_URL = 'https://api.x.com/2/tweets';

it('creates a post through the tweets endpoint with the bearer token', function () {
    Http::fake([TWEETS_URL => Http::response(['data' => ['id' => '1900000000000000001', 'text' => 'ok']], 201)]);

    $post = XPost::make('https://example.com/blog/q-a', 'Q&A (part_1) #2')
        ->description('Answers [draft]');

    $id = app(PostsClient::class)->create('user-token', $post);

    expect($id)->toBe('1900000000000000001');

    Http::assertSent(fn (Request $request) => $request->url() === TWEETS_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer user-token')
        && $request['text'] === "Q&A (part_1) #2\n\nAnswers [draft]\n\nhttps://example.com/blog/q-a");
});

it('posts only the title and link when there is no description', function () {
    Http::fake([TWEETS_URL => Http::response(['data' => ['id' => '2']], 201)]);

    app(PostsClient::class)->create('t', XPost::make('https://example.com/x', 'Hello'));

    Http::assertSent(fn (Request $request) => $request['text'] === "Hello\n\nhttps://example.com/x");
});

it('reports a 401 as unauthorized with X\'s message', function () {
    Http::fake([TWEETS_URL => Http::response(['title' => 'Unauthorized', 'detail' => 'Invalid access token'], 401)]);

    try {
        app(PostsClient::class)->create('t', XPost::make('https://example.com/x', 'X'));
        $this->fail('Expected XRequestFailed');
    } catch (XRequestFailed $e) {
        expect($e->isUnauthorized())->toBeTrue()
            ->and($e->status)->toBe(401)
            ->and($e->getMessage())->toBe('X rejected the request: HTTP 401');
    }
});

it('reports other errors with X\'s message or the status', function (int $status, mixed $body, string $message) {
    Http::fake([TWEETS_URL => Http::response($body, $status)]);

    expect(fn () => app(PostsClient::class)->create('t', XPost::make('https://example.com/x', 'X')))
        ->toThrow(XRequestFailed::class, $message);
})->with([
    'json message' => [403, ['message' => 'You are not permitted to create this post'], 'X rejected the request: You are not permitted to create this post'],
    'no body' => [503, null, 'X rejected the request: HTTP 503'],
]);

it('treats a 2xx without a post id as posted, with no id', function () {
    // The post exists on X; failing here would make a retry post it twice.
    Http::fake([TWEETS_URL => Http::response(['data' => []], 201)]);

    expect(app(PostsClient::class)->create('t', XPost::make('https://example.com/x', 'X')))
        ->toBeNull();
});

it('reports a network failure as an X request failure', function () {
    Http::fake([TWEETS_URL => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => app(PostsClient::class)->create('t', XPost::make('https://example.com/x', 'X')))
        ->toThrow(XRequestFailed::class, 'Could not reach X: timed out');
});
