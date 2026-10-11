<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Facades\X;
use Siberfx\XAutopost\Jobs\ShareOnX;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\Fixtures\RelativeUrlPost;
use Siberfx\XAutopost\XManager;

beforeEach(function () {
    Event::fake([ShareFailed::class]);
    app(TokenStore::class)->put(new StoredConnection('member-token', null, connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('fails at once, without retrying, when X refuses the request', function (int $status) {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['message' => 'No'], $status)]);

    dispatch(new ShareOnX('post', $this->post->id)); // sync queue: the job is marked failed, nothing is thrown

    $row = XSharedPost::query()->for($this->post)->sole();
    expect($row->status)->toBe(XSharedPost::STATUS_FAILED)
        ->and($row->error)->toBe('X rejected the request: No');
    Http::assertSentCount(1);
    Event::assertDispatchedTimes(ShareFailed::class, 1);
})->with(['unauthorized' => 401, 'forbidden' => 403, 'unprocessable' => 422]);

it('fails at once when the model builds an invalid post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Bad', 'status' => 'published']);

    dispatch(new ShareOnX(RelativeUrlPost::class, $post->id));

    expect(XSharedPost::query()->where('status', XSharedPost::STATUS_FAILED)->count())->toBe(1);
    Http::assertNothingSent();
    Event::assertDispatchedTimes(ShareFailed::class, 1);
});

it('rethrows failures that may succeed later, so the queue retries them', function (int $status) {
    Http::fake(['https://api.x.com/2/tweets' => Http::response(['message' => 'Busy'], $status)]);

    expect(fn () => (new ShareOnX('post', $this->post->id))->handle(app(XManager::class)))
        ->toThrow(XRequestFailed::class, 'X rejected the request: Busy');
    expect(XSharedPost::query()->count())->toBe(0);
    Event::assertNotDispatched(ShareFailed::class);
})->with(['rate limited' => 429, 'server error' => 500, 'unavailable' => 503]);

it('rethrows a network failure for retry', function () {
    Http::fake(['https://api.x.com/2/tweets' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new ShareOnX('post', $this->post->id))->handle(app(XManager::class)))
        ->toThrow(XRequestFailed::class, 'Could not reach X: timed out');
    Event::assertNotDispatched(ShareFailed::class);
});

it('is unique per shareable while queued', function () {
    $job = new ShareOnX('post', 42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('post:42')
        ->and($job->uniqueFor)->toBe(600);
});

it('does not queue a second share for the same model while one is waiting', function () {
    Queue::fake();
    $other = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Other', 'status' => PostStatus::Published]));

    X::queue($this->post);
    X::queue($this->post);
    X::queue($other);

    Queue::assertPushed(ShareOnX::class, 2);
});
