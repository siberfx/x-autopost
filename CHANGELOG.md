# Changelog

All notable changes to `siberfx/x-autopost` are documented here. This project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]
n## [1.0.0] - 2026-10-11

### Added
- Connect one X account through OAuth 2.0 with PKCE (`/x/redirect`, `/x/callback`). The refresh token is stored encrypted; a request X refuses with 401 is retried once after a refresh, and the rotated pair is stored each time.
- Share Eloquent models as posts (`POST /2/tweets`). Text is composed to fit X's 280-character limit, counting every URL as 23 characters.
- Auto-post when a model becomes live: the `ShareableOnX` contract and the `PostsToX` trait. Off by default, never from the console unless enabled, at most once per model, dispatched after commit.
- Share history in `x_posts`; tokens encrypted in `x_connections` behind a swappable `TokenStore`.
- Connection status from `GET /2/users/me`, cached for five minutes: account name, username, picture, scopes; active, expired or unknown.
- JSON endpoints for status, disconnect and share; Artisan commands `x:install`, `x:status`, `x:disconnect`, `x:share`, `x:check-token`, `x:test-notification`; events `Connected`, `Disconnected`, `Shared`, `ShareFailed`, `TokenExpired`.
- Telegram, Slack and email notifications, off by default, queued or sent at once, one per channel and event. A failing channel never fails the share.
- Ready-made admin UI in Tailwind and Bootstrap 5 (`X_UI_THEME`): a connection card, a share button and a flash message. Livewire variants when Livewire is installed. Headless mode with `X_UI=false`.
- The share and disconnect routes answer HTML forms (redirect back with a message) as well as JSON; a signed share route lets the button work without a morph map.
- Non-ASCII URLs are accepted: paths are percent-encoded and internationalised hosts converted to punycode (with `ext-intl`).
- The auto-post job is unique per model, retries `429`, `5xx` and network errors, and fails at once on any other `4xx` or an invalid post.
- PHP 8.4 and 8.5 (8.6 accepted), Laravel 12 and 13.
