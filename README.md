# X Autopost for Laravel

Connect one X account to your Laravel app and post your models to X automatically when they go
live, or whenever you choose. Posts go through X's API (`POST /2/tweets`): the title, an optional
description and the link, kept within X's 280-character limit.

- One site-wide X **account** (the person who clicks Connect), authorised with OAuth 2.0 + PKCE.
- Auto-post when a model becomes live, at most once, off until you switch it on.
- Ready-made connection card and share button (Tailwind or Bootstrap 5, Blade or Livewire), plus routes, JSON endpoints, Artisan commands and events.
- Headless mode (`X_UI=false`) when you want the package without any UI.
- Optional Telegram, Slack and email messages on every post, failed share and lost connection.

Requires PHP 8.4+ and Laravel 12 or 13.

## Contents

- [Quick start](#quick-start)
- [1. Set up the X app](#1-set-up-the-x-app)
- [2. Install](#2-install)
- [3. Connect](#3-connect)
- [4. Make a model shareable](#4-make-a-model-shareable)
- [5. Share on demand](#5-share-on-demand)
- [6. Ready-made admin UI](#6-ready-made-admin-ui)
- [7. Commands and events](#7-commands-and-events)
- [8. Notifications](#8-notifications)
- [9. Headless: no UI](#9-headless-no-ui)
- [10. Configuration reference](#10-configuration-reference)
- [11. Troubleshooting](#11-troubleshooting)

## Quick start

```bash
composer require siberfx/x-autopost
php artisan x:install --theme=tailwind
php artisan migrate
```

```dotenv
X_CLIENT_ID=...
X_CLIENT_SECRET=...        # optional for a public (PKCE-only) app
X_AUTOPOST=true
```

```php
// app/Providers/AppServiceProvider.php → boot()
Gate::define('manage-x-autopost', fn (User $user) => $user->is_admin);
```

```php
// app/Models/Article.php
class Article extends Model implements ShareableOnX
{
    use PostsToX;

    public function isLiveForX(): bool
    {
        return $this->status === 'published';
    }

    public function toXPost(): XPost
    {
        return XPost::make(route('blog.show', $this), $this->title)
            ->description(Str::limit(strip_tags($this->body), 200));
    }
}
```

```blade
{{-- resources/views/admin/settings.blade.php --}}
<x-x-autopost::connection />
```

Open the settings page, click **Connect X**, run `php artisan queue:work`, and publish an article:
it is posted to X.

## 1. Set up the X app

1. Create a project and app in the X developer portal (<https://developer.x.com/en/portal/dashboard>).
   Posting needs an access tier that allows `POST /2/tweets`.
2. **User authentication settings**: enable **OAuth 2.0**, choose the app type (a *Web App* with a
   client secret, or a *Native/Public* app for PKCE only) and the permissions **Read and write**.
3. **Callback URI / Redirect URL**: add your callback URL exactly, e.g. `https://example.com/x/callback`
   (scheme, host, path, no trailing slash). Add one per environment.
4. Copy the **Client ID** and, for a confidential app, the **Client Secret**.

Scopes requested: `tweet.read tweet.write users.read offline.access`. `offline.access` gives the
refresh token that keeps the connection alive without asking the admin again.

## 2. Install

```bash
composer require siberfx/x-autopost
php artisan x:install                  # publishes config + migrations, asks tailwind or bootstrap
php artisan migrate
```

`x:install` options: `--theme=bootstrap` (no question asked) and `--headless` (no UI, see section 9).
It publishes `config/x-autopost.php` and the two migrations, writes `X_UI_THEME` (or `X_UI=false`) to
`.env` when missing, and prints the callback URL to register.

```bash
php artisan vendor:publish --tag=x-autopost-config
php artisan vendor:publish --tag=x-autopost-migrations
php artisan vendor:publish --tag=x-autopost-views     # only when the UI is enabled
php artisan vendor:publish --tag=x-autopost-stubs
```

> **Using UUID or ULID keys?** Edit the published `create_x_posts_table` migration: replace
> `morphs('shareable')` with `uuidMorphs('shareable')` / `ulidMorphs('shareable')` before migrating.

**Required: say who may manage X.** Connecting, disconnecting, seeing the connection and sharing are
all guarded by the `manage-x-autopost` gate. The package defines it to **deny everyone** until your
app defines it. Any rule works:

```php
Gate::define('manage-x-autopost', fn (User $user) => $user->hasRole('marketing'));
```

The routes sit behind `['web', 'auth', 'can:manage-x-autopost']`. Change the middleware, the URL
prefix or the page you come back to in `config/x-autopost.php`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'admin/x',               // → /admin/x/redirect, /admin/x/callback …
    'name' => 'x-autopost.',
    'middleware' => ['web', 'auth:admin', 'can:manage-x-autopost'],
    'after_connect' => '/admin/settings',
],
```

Changing the prefix changes the callback URL: register the new one on your X app.

## 3. Connect

Send an admin to `route('x-autopost.redirect')`. After X's consent screen they land on
`after_connect` with `?x=…`:

| `?x=` | Meaning |
|---|---|
| `connected` | Done. |
| `cancelled` | The person cancelled on X. |
| `error&reason=…` | X refused, e.g. a scope the app does not have. |
| `exchange_failed` | The code could not be exchanged; the reason is in your log. Usually a callback URL mismatch. |
| `invalid_state` | The login attempt expired or was replayed. Connect again. |
| `missing_code` | X came back without an authorization code. Connect again. |
| `not_configured` | `X_CLIENT_ID` is missing. |

Check the connection anywhere:

```php
use Siberfx\XAutopost\Facades\X;

if (X::isConnected()) {
    $connection = X::connection();

    $connection->name;        // "Ada Lovelace"
    $connection->username;    // "ada"
    $connection->status;      // active | expired | revoked | unknown
    $connection->toArray();   // everything above as an array, never a token
}
```

`connection()` asks X (`GET /2/users/me`) at most once every `status_cache_ttl` seconds (300 by
default). `isConnected()` only checks that a token is stored and never calls X.

**Tokens.** The access token lasts about two hours. When X answers 401 the package refreshes it with
the refresh token and retries once; X rotates the refresh token on every refresh, so both are stored
each time. If the refresh is refused too, the connection shows as `expired` and the admin must connect
again.

## 4. Make a model shareable

```php
use Illuminate\Support\Str;
use Siberfx\XAutopost\Concerns\PostsToX;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Posts\XPost;

class Article extends Model implements ShareableOnX
{
    use PostsToX;

    public function isLiveForX(): bool
    {
        return $this->status === 'published';
    }

    public function toXPost(): XPost
    {
        return XPost::make(url: route('blog.show', $this), title: $this->title)
            ->description(Str::limit(strip_tags($this->body), 200))
            ->commentary("{title}\n\n{description}\n\n{url}");
    }
}
```

- **`isLiveForX()`** decides when auto-post fires (the save on which the model turns from `false` to
  `true`) and whether a manual share is allowed.
- **`toXPost()`** says what to post. `XPost::make($url, $title)` is the link and the headline;
  `description()` adds text below the headline; `commentary()` is an optional template with
  `{title}`, `{description}` and `{url}`.

The post always fits X's limit: the link counts as 23 characters, and the description is shortened
with an ellipsis to make room. A custom commentary whose text cannot fit even without its description
throws `InvalidArgumentException` when shared, so the post is not sent.

| `XPost` | Limit | Default |
|---|---|---|
| `make(string $url, string $title)` | title cut to 200 characters | — |
| `->description(string)` | shortened to fit the 280 characters | empty |
| `->commentary(string $template)` | must fit 280 characters | `"{title}\n\n{description}\n\n{url}"` |
| `->text()` | the final post text | — |

`XPost` is immutable: each method returns a copy.

With `X_AUTOPOST=true`, an `Article` is queued for X when it is **created published** or **changes
from draft to published**, once. Editing a published article does not post again. Seeders and Artisan
commands do not auto-post (set `autopost.in_console` to allow it). Auto-post reacts to model saves
through Eloquent events, so mass `update()` queries and `saveQuietly()` do not auto-post.

```php
Article::create(['title' => 'Hello', 'status' => 'published']);  // queued
$draft->update(['status' => 'published']);                        // queued (draft → published)
$published->update(['title' => 'Typo fixed']);                    // not queued: it was already live
Article::where('id', 5)->update(['status' => 'published']);       // not queued: no model events
```

Run a queue worker. The job is dispatched after the database transaction commits, is unique per
model while it waits, and retries 3 times when X is busy or unreachable (429, 5xx, network). A request
X refuses outright (401 after a failed refresh, 403, 422 …) or an invalid post fails at once.

```php
'autopost' => [
    'enabled' => (bool) env('X_AUTOPOST', false),
    'in_console' => false,
    'queue_connection' => 'redis',   // null = your default connection
    'queue' => 'x',                  // null = the default queue
],
```

```bash
php artisan queue:work redis --queue=x,default
```

## 5. Share on demand

```php
use Siberfx\XAutopost\Facades\X;

X::share($article);           // now; returns the XSharedPost record (re-sharing is allowed)
X::queue($article);           // in the background
X::wasPosted($article);       // bool, same as $article->wasPostedToX()
$article->wasPostedToX();     // bool
$article->xPosts;             // history: post_id, status, trigger, error, posted_at
```

`share()` throws `NotConnected`, `NotShareable` (not live), `XRequestFailed` (X's message) or
`InvalidArgumentException` (an invalid URL or a text that does not fit). A post X accepted without
returning its id is recorded as posted with a `null` `post_id`.

## 6. Ready-made admin UI

```blade
<x-x-autopost::connection />                                  {{-- connect, status, disconnect --}}
<x-x-autopost::share-button :model="$article" />              {{-- share or share again --}}
<x-x-autopost::share-button :model="$article" label="Post it" :confirm="false" />
<x-x-autopost::flash />                                       {{-- messages after a redirect --}}
```

With Livewire installed the same components are available as `<livewire:x-autopost.connection />`
and `<livewire:x-autopost.share-button :model="$article" />`. Every action checks the gate; people
it refuses see the connection read-only and the share button disabled.

The theme comes from `X_UI_THEME` (`tailwind` or `bootstrap`). For a custom look, publish the views
(`vendor:publish --tag=x-autopost-views`) and point `ui.theme` at your own folder of views.

Tailwind v4 needs the package classes compiled:

```css
@source '../../vendor/siberfx/x-autopost/resources/views/tailwind';
```

## 7. Commands and events

| Command | Does |
|---|---|
| `x:install` | Publishes config and migrations, chooses the theme. |
| `x:status` | Shows the connected account and its status. |
| `x:disconnect` | Revokes the token at X and forgets it. |
| `x:share {model} {id}` | Shares one model now. |
| `x:check-token` | Fires `TokenExpired` when X no longer accepts the connection. Schedule it daily. |
| `x:test-notification` | Sends a test message to the enabled notification channels. |

| Event | Fired when |
|---|---|
| `Connected` | The account is connected. |
| `Disconnected` | The connection is removed; `revoked` tells whether X confirmed it. |
| `Shared` | A post went out (`shareable`, `post`). |
| `ShareFailed` | An automatic share gave up. |
| `TokenExpired` | `x:check-token` found X refusing the connection. |

## 8. Notifications

Messages go to Telegram, Slack and/or email when a post goes out, an automatic share gives up, or X
stops accepting the connection. They are off until you switch them on:

```dotenv
X_NOTIFY=true
X_NOTIFY_TELEGRAM=true
X_TELEGRAM_BOT_TOKEN=...
X_TELEGRAM_CHAT_ID=...
X_NOTIFY_SLACK=true
X_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
X_NOTIFY_MAIL=true
X_NOTIFY_MAIL_TO=ops@example.com,ceo@example.com
```

Messages are queued by default (`X_NOTIFY_QUEUED=false` sends them at once). A failing channel never
fails the share. Add your own channel by implementing `Siberfx\XAutopost\Contracts\NotificationChannel`
and listing it under `notifications.channels`.

## 9. Headless: no UI

```bash
php artisan x:install --headless          # sets X_UI=false
```

Headless mode registers no views, Blade or Livewire components; the routes answer JSON only. Connect
an admin with a link to `route('x-autopost.redirect')`, then use the facade, events and endpoints:

```php
X::share($article);
X::connection()->toArray();
```

## 10. Configuration reference

| Key | Env | Default |
|---|---|---|
| `client_id` | `X_CLIENT_ID` | — |
| `client_secret` | `X_CLIENT_SECRET` | null (public app) |
| `redirect_uri` | `X_REDIRECT_URI` | the package callback route |
| `scopes` | — | `tweet.read tweet.write users.read offline.access` |
| `routes.prefix` / `routes.name` | — | `x` / `x-autopost.` |
| `routes.middleware` | — | `['web', 'auth', 'can:manage-x-autopost']` |
| `routes.after_connect` | — | `/` |
| `autopost.enabled` | `X_AUTOPOST` | `false` |
| `autopost.in_console` | — | `false` |
| `autopost.queue_connection` / `queue` | — | the defaults |
| `ui.enabled` | `X_UI` | `true` |
| `ui.theme` | `X_UI_THEME` | `tailwind` |
| `notifications.enabled` | `X_NOTIFY` | `false` |
| `token_store` | — | `DatabaseTokenStore` |
| `http.timeout` | — | `20` seconds |
| `job.tries` / `job.backoff` | — | `3` / `[60, 300]` |
| `status_cache_ttl` | — | `300` seconds |

## 11. Troubleshooting

- **"The redirect_uri does not match"** on X's page: the URL the app sent is not in the app's
  callback list character for character. Run `php artisan route:list --name=x-autopost.callback` or
  set `X_REDIRECT_URI`.
- **`?x=exchange_failed`**: check the log for `X token exchange failed.` The usual causes are the
  callback mismatch above or a client secret that does not match a confidential app.
- **Status shows `expired`** after a long pause: the refresh token was refused. Connect again.
- **Posts fail with 403** on X: the app's access tier does not allow posting.

## Testing your app

```php
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Facades\X;

Http::fake(['https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '1']], 201)]);
```

## License

MIT. See [LICENSE](LICENSE).
