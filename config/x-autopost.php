<?php

declare(strict_types=1);

use Siberfx\XAutopost\Notifications\Channels\MailChannel;
use Siberfx\XAutopost\Notifications\Channels\SlackChannel;
use Siberfx\XAutopost\Notifications\Channels\TelegramChannel;
use Siberfx\XAutopost\Stores\DatabaseTokenStore;

return [

    /*
    | Credentials of your X app (developer portal → Projects & Apps → Keys and tokens).
    | The client secret is optional: a public app with PKCE only leaves it empty.
    */
    'client_id' => env('X_CLIENT_ID'),
    'client_secret' => env('X_CLIENT_SECRET'),

    /*
    | Must equal one of the app's "Callback URI / Redirect URL" values exactly. Null uses
    | the package callback route, e.g. https://example.com/x/callback.
    */
    'redirect_uri' => env('X_REDIRECT_URI'),

    // tweet.write posts; offline.access returns the refresh token that keeps the connection alive.
    'scopes' => ['tweet.read', 'tweet.write', 'users.read', 'offline.access'],

    'routes' => [
        'enabled' => true,
        'prefix' => 'x',
        'name' => 'x-autopost.',
        // The gate denies everyone until your app defines it, e.g. in AppServiceProvider::boot():
        // Gate::define('manage-x-autopost', fn (User $user) => $user->is_admin);
        'middleware' => ['web', 'auth', 'can:manage-x-autopost'],
        // Where the OAuth callback sends the browser; ?x=connected|cancelled|error|… is appended.
        'after_connect' => '/',
    ],

    'autopost' => [
        'enabled' => (bool) env('X_AUTOPOST', false),
        // Seeders and Artisan commands do not auto-post unless this is true.
        'in_console' => false,
        'queue_connection' => null,
        'queue' => null,
    ],

    'ui' => [
        // false = headless: no views, Blade or Livewire components, and the
        // routes answer JSON only. Use the facade, events and endpoints yourself.
        'enabled' => (bool) env('X_UI', true),
        // tailwind | bootstrap | the name of a folder of published views
        'theme' => env('X_UI_THEME', 'tailwind'),
    ],

    /*
    | Messages sent to Telegram, Slack and/or email when a post goes out, an
    | automatic share gives up, or X stops accepting the connection
    | (x:check-token). A failing channel never fails the share.
    | Try your settings with: php artisan x:test-notification
    */
    'notifications' => [
        'enabled' => (bool) env('X_NOTIFY', false),

        'events' => [
            'shared' => true,
            'failed' => true,
            'token_expired' => true,
        ],

        // true: one queued job per channel, retried `tries` times with `backoff`.
        // false: sent at once inside the request or job that shared, no retries.
        'queued' => (bool) env('X_NOTIFY_QUEUED', true),
        'queue_connection' => null,
        'queue' => null,
        'tries' => 3,
        'backoff' => [30, 120],

        'channels' => [
            'telegram' => [
                'enabled' => (bool) env('X_NOTIFY_TELEGRAM', false),
                'class' => TelegramChannel::class,
                // From @BotFather; add the bot to the chat first.
                'bot_token' => env('X_TELEGRAM_BOT_TOKEN'),
                // A user, group (-100…) or @channel id.
                'chat_id' => env('X_TELEGRAM_CHAT_ID'),
                // Forum topic id in a group with topics, or null.
                'thread_id' => env('X_TELEGRAM_THREAD_ID'),
            ],
            'slack' => [
                'enabled' => (bool) env('X_NOTIFY_SLACK', false),
                'class' => SlackChannel::class,
                // Incoming webhook URL (api.slack.com → Your apps → Incoming Webhooks).
                'webhook_url' => env('X_SLACK_WEBHOOK_URL'),
            ],
            'mail' => [
                'enabled' => (bool) env('X_NOTIFY_MAIL', false),
                'class' => MailChannel::class,
                // One address or several, comma separated: "ops@example.com,ceo@example.com".
                'to' => env('X_NOTIFY_MAIL_TO'),
                // A mailer from config/mail.php; null uses your default mailer and from address.
                'mailer' => env('X_NOTIFY_MAILER'),
            ],
            // Your own: 'teams' => ['enabled' => true, 'class' => App\TeamsChannel::class, …],
            // a class implementing Siberfx\XAutopost\Contracts\NotificationChannel.
        ],
    ],

    'token_store' => DatabaseTokenStore::class,

    'http' => [
        'timeout' => 20,
    ],

    'job' => [
        'tries' => 3,
        'backoff' => [60, 300],
    ],

    /*
    | X's access token lasts about two hours and is renewed with the refresh token
    | on demand, so the connection status is the only thing that needs watching.
    */
    'status_cache_ttl' => 300,

];
