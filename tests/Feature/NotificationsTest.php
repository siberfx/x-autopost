<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\Connection;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Events\TokenExpired;
use Siberfx\XAutopost\Exceptions\NotificationFailed;
use Siberfx\XAutopost\Facades\X;
use Siberfx\XAutopost\Jobs\SendNotification;
use Siberfx\XAutopost\Notifications\Message;
use Siberfx\XAutopost\Notifications\NotificationMail;
use Siberfx\XAutopost\Notifications\Notifier;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\Tests\Fixtures\PostStatus;
use Siberfx\XAutopost\Tests\Fixtures\RecordingChannel;

const TELEGRAM = 'https://api.telegram.org/bot123:secret/sendMessage';
const SLACK = 'https://hooks.slack.com/services/T0/B0/xyz';

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', null, connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Fish & <Chips>', 'status' => PostStatus::Published]));

    config([
        'x-autopost.notifications.channels.telegram.bot_token' => '123:secret',
        'x-autopost.notifications.channels.telegram.chat_id' => '-10042',
        'x-autopost.notifications.channels.slack.webhook_url' => SLACK,
    ]);
});

function enableNotifications(string ...$channels): void
{
    config(['x-autopost.notifications.enabled' => true]);

    foreach ($channels as $channel) {
        config(["x-autopost.notifications.channels.{$channel}.enabled" => true]);
    }
}

function fakeXAndChannels(): void
{
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '7']], 201),
        TELEGRAM => Http::response(['ok' => true, 'result' => []]),
        SLACK => Http::response('ok'),
    ]);
}

it('is off by default', function () {
    expect(config('x-autopost.notifications.enabled'))->toBeFalse()
        ->and(config('x-autopost.notifications.channels.telegram.enabled'))->toBeFalse()
        ->and(config('x-autopost.notifications.channels.slack.enabled'))->toBeFalse();

    fakeXAndChannels();
    X::share($this->post);

    Http::assertSentCount(1);
});

it('sends nothing while the master switch is off, even with channels enabled', function () {
    config(['x-autopost.notifications.channels.telegram.enabled' => true]);
    fakeXAndChannels();

    X::share($this->post);

    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.telegram.org'));
});

it('tells Telegram about a post, escaped for HTML', function () {
    enableNotifications('telegram');
    fakeXAndChannels();

    X::share($this->post);

    Http::assertSent(function (Request $request) {
        if ($request->url() !== TELEGRAM) {
            return false;
        }

        expect($request['chat_id'])->toBe('-10042')
            ->and($request['parse_mode'])->toBe('HTML')
            ->and($request['text'])->toContain('<b>✅ Shared on X: Fish &amp; &lt;Chips&gt;</b>')
            ->and($request['text'])->toContain('https://example.com/posts/'.$this->post->id)
            ->and($request['text'])->toContain('Post: https://x.com/i/web/status/7')
            ->and($request['text'])->toContain('Trigger: manual')
            ->and($request->data())->not->toHaveKey('message_thread_id');

        return true;
    });
});

it('posts to a Telegram forum topic when a thread id is set', function () {
    enableNotifications('telegram');
    config(['x-autopost.notifications.channels.telegram.thread_id' => '9']);
    fakeXAndChannels();

    X::share($this->post);

    Http::assertSent(fn (Request $request) => $request->url() === TELEGRAM && $request['message_thread_id'] === 9);
});

it('tells Slack about a post', function () {
    enableNotifications('slack');
    fakeXAndChannels();

    X::share($this->post);

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], '*✅ Shared on X: Fish &amp; &lt;Chips&gt;*')
        && str_contains($request['text'], '7'));
});

it('sends to both channels when both are on', function () {
    enableNotifications('telegram', 'slack');
    fakeXAndChannels();

    X::share($this->post);

    Http::assertSentCount(3);
});

it('can switch off the message for a single event', function () {
    enableNotifications('slack');
    config(['x-autopost.notifications.events.shared' => false]);
    fakeXAndChannels();

    X::share($this->post);

    Http::assertNotSent(fn (Request $request) => $request->url() === SLACK);
});

it('skips an enabled channel that lacks its settings', function () {
    enableNotifications('telegram', 'slack');
    config(['x-autopost.notifications.channels.telegram.chat_id' => null]);
    fakeXAndChannels();

    X::share($this->post);

    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.telegram.org'));
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
});

it('never fails the share when a channel fails, and still sends to the others', function () {
    Exceptions::fake();
    enableNotifications('telegram', 'slack');
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '7']], 201),
        TELEGRAM => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
        SLACK => Http::response('ok'),
    ]);

    $record = X::share($this->post);

    expect($record->post_id)->toBe('7');
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
    Exceptions::assertReported(fn (NotificationFailed $e) => $e->getMessage() === 'Telegram refused the message: Bad Request: chat not found');
});

it('keeps the bot token out of connection errors', function () {
    Http::fake([TELEGRAM => fn () => throw new ConnectionException('cURL error 28 for '.TELEGRAM)]);
    config(['x-autopost.notifications.channels.telegram.enabled' => true]);

    expect(fn () => app(Notifier::class)->sendNow('telegram', Message::test()))
        ->toThrow(NotificationFailed::class, 'Could not reach Telegram: cURL error 28 for https://api.telegram.org/bot***/sendMessage');
});

it('keeps the Slack webhook out of connection errors', function () {
    Http::fake([SLACK => fn () => throw new ConnectionException('timed out: '.SLACK)]);

    expect(fn () => app(Notifier::class)->sendNow('slack', Message::test()))
        ->toThrow(NotificationFailed::class, 'Could not reach Slack: timed out: ***');
});

it('reports a share that gave up', function () {
    enableNotifications('slack');
    fakeXAndChannels();

    event(new ShareFailed('post', 5, new RuntimeException('X rejected the request: No')));

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], '*❌ X share failed*')
        && str_contains($request['text'], 'Model: post #5')
        && str_contains($request['text'], 'Error: X rejected the request: No'));
});

it('queues one job per channel on the configured queue', function () {
    Queue::fake();
    enableNotifications('telegram', 'slack');
    config(['x-autopost.notifications.queue_connection' => 'redis', 'x-autopost.notifications.queue' => 'notifications']);
    fakeXAndChannels();

    X::share($this->post);

    Queue::assertPushed(SendNotification::class, 2);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->channel === 'telegram'
        && $job->connection === 'redis' && $job->queue === 'notifications' && $job->tries === 3);
});

it('drops a queued message when notifications were switched off meanwhile', function () {
    enableNotifications('slack');
    Http::fake();
    $job = new SendNotification('slack', Message::test());
    config(['x-autopost.notifications.enabled' => false]);

    $job->handle(app(Notifier::class));

    Http::assertNothingSent();
});

it('sends to a channel of your own', function () {
    enableNotifications();
    config(['x-autopost.notifications.channels.custom' => ['enabled' => true, 'class' => RecordingChannel::class, 'prefix' => '>']]);
    fakeXAndChannels();
    RecordingChannel::$sent = [];

    X::share($this->post);

    expect(RecordingChannel::$sent)->toBe(['> Shared on X: Fish & <Chips>']);
});

it('tests the enabled channels from the console', function () {
    enableNotifications('telegram', 'slack');
    fakeXAndChannels();

    $this->artisan('x:test-notification')
        ->expectsOutputToContain('telegram: sent.')
        ->expectsOutputToContain('slack: sent.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === SLACK && str_contains($request['text'], 'X Autopost test notification'));
});

it('fails the console test when a channel is missing settings or refuses', function () {
    config(['x-autopost.notifications.channels.slack.enabled' => true, 'x-autopost.notifications.channels.slack.webhook_url' => null]);

    $this->artisan('x:test-notification')
        ->expectsOutputToContain('Notifications are off')
        ->expectsOutputToContain('slack: missing settings')
        ->assertFailed();

    Http::fake([TELEGRAM => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('x:test-notification', ['--channel' => ['telegram']])
        ->expectsOutputToContain('telegram: Telegram refused the message: Unauthorized')
        ->assertFailed();
});

it('says so when no channel is enabled', function () {
    $this->artisan('x:test-notification')
        ->expectsOutputToContain('No notification channel is enabled')
        ->assertFailed();
});

it('emails the configured addresses', function () {
    Mail::fake();
    enableNotifications('mail');
    config(['x-autopost.notifications.channels.mail.to' => 'ops@example.com, ceo@example.com, not-an-email']);
    fakeXAndChannels();

    X::share($this->post);

    Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('ops@example.com') && $mail->hasTo('ceo@example.com') && ! $mail->hasTo('not-an-email')
            && $mail->envelope()->subject === '✅ Shared on X: Fish & <Chips>'
            && str_contains($html, '<strong>Shared on X: Fish &amp; &lt;Chips&gt;</strong>')
            && str_contains($html, '<p>Post: https://x.com/i/web/status/7</p>')
            && str_contains($html, '<a href="https://example.com/posts/'.$this->post->id.'">');
    });
});

it('sends the email through the configured mailer', function () {
    Mail::fake();
    enableNotifications('mail');
    config([
        'x-autopost.notifications.channels.mail.to' => 'ops@example.com',
        'x-autopost.notifications.channels.mail.mailer' => 'array',
    ]);

    app(Notifier::class)->sendNow('mail', Message::test());

    Mail::mailer('array')->assertSent(NotificationMail::class);
});

it('skips the mail channel without a valid address', function () {
    Mail::fake();
    enableNotifications('mail');
    config(['x-autopost.notifications.channels.mail.to' => 'nobody']);
    fakeXAndChannels();

    X::share($this->post);

    Mail::assertNothingSent();
});

it('reports a mail transport failure without failing the share', function () {
    Exceptions::fake();
    enableNotifications('mail');
    config([
        'x-autopost.notifications.channels.mail.to' => 'ops@example.com',
        'x-autopost.notifications.channels.mail.mailer' => 'missing-mailer',
    ]);
    fakeXAndChannels();

    expect(X::share($this->post)->post_id)->toBe('7');
    Exceptions::assertReported(fn (NotificationFailed $e) => str_starts_with($e->getMessage(), 'Could not send the email: Mailer [missing-mailer] is not defined.'));
});

it('queues notifications by default', function () {
    expect(config('x-autopost.notifications.queued'))->toBeTrue();
});

it('sends at once, without a queue, when queued is off', function () {
    Queue::fake();
    enableNotifications('slack');
    config(['x-autopost.notifications.queued' => false]);
    fakeXAndChannels();

    X::share($this->post);

    Queue::assertNotPushed(SendNotification::class);
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
});

it('never fails the share when an unqueued channel fails', function () {
    Exceptions::fake();
    enableNotifications('slack');
    config(['x-autopost.notifications.queued' => false]);
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '7']], 201),
        SLACK => Http::response('invalid_token', 403),
    ]);

    expect(X::share($this->post)->post_id)->toBe('7');
    Exceptions::assertReported(fn (NotificationFailed $e) => $e->getMessage() === 'Slack refused the message: invalid_token');
});

it('warns when X no longer accepts the connection', function () {
    enableNotifications('slack');
    fakeXAndChannels();
    $connection = new Connection(connected: true, status: 'expired', name: 'Ada Lovelace', username: 'ada');

    event(new TokenExpired($connection));

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], 'X no longer accepts the connection')
        && str_contains($request['text'], 'Account: @ada'));
});
