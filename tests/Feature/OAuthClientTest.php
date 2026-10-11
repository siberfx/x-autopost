<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\XAutopost\Exceptions\NotConfigured;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\OAuth\OAuthClient;

const X_TOKEN_URL = 'https://api.x.com/2/oauth2/token';
const X_ME_URL = 'https://api.x.com/2/users/me';

function authorizeQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('builds the PKCE authorization url with the callback route by default', function () {
    $url = app(OAuthClient::class)->authorizationUrl('state-123', 'challenge-abc');

    expect($url)->toStartWith('https://x.com/i/oauth2/authorize?')
        ->and(authorizeQuery($url))->toBe([
            'response_type' => 'code',
            'client_id' => 'client-id',
            'redirect_uri' => route('x-autopost.callback'),
            'state' => 'state-123',
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'code_challenge' => 'challenge-abc',
            'code_challenge_method' => 'S256',
        ]);
});

it('uses a configured redirect uri', function () {
    config(['x-autopost.redirect_uri' => 'https://www.example.com/x/callback']);

    expect(authorizeQuery(app(OAuthClient::class)->authorizationUrl('s', 'c'))['redirect_uri'])
        ->toBe('https://www.example.com/x/callback');
});

it('refuses to build a url without a client id', function () {
    config(['x-autopost.client_id' => null]);

    app(OAuthClient::class)->authorizationUrl('s', 'c');
})->throws(NotConfigured::class);

it('derives the S256 challenge from the verifier (RFC 7636 example)', function () {
    expect(OAuthClient::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))
        ->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
    expect(OAuthClient::newCodeVerifier())->toHaveLength(64);
});

it('exchanges a code for a stored connection with the verifier and the same redirect uri', function () {
    CarbonImmutable::setTestNow('2026-10-02 12:00:00');
    config(['x-autopost.redirect_uri' => 'https://www.example.com/x/callback']);
    Http::fake([
        X_TOKEN_URL => Http::response([
            'access_token' => 'user-token',
            'refresh_token' => 'refresh-1',
            'expires_in' => 7200,
            'scope' => 'tweet.read tweet.write users.read offline.access',
        ]),
        X_ME_URL.'*' => Http::response(['data' => [
            'id' => '42',
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'profile_image_url' => 'https://pbs.twimg.com/a.jpg',
        ]]),
    ]);

    $connection = app(OAuthClient::class)->exchange('auth-code', 'verifier-xyz');

    expect($connection->accessToken)->toBe('user-token')
        ->and($connection->refreshToken)->toBe('refresh-1')
        ->and($connection->username)->toBe('ada')
        ->and($connection->name)->toBe('Ada Lovelace')
        ->and($connection->picture)->toBe('https://pbs.twimg.com/a.jpg')
        ->and($connection->scopes)->toBe(['tweet.read', 'tweet.write', 'users.read', 'offline.access'])
        ->and($connection->connectedAt?->toDateTimeString())->toBe('2026-10-02 12:00:00');

    Http::assertSent(fn (Request $request) => $request->url() === X_TOKEN_URL
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === 'verifier-xyz'
        && $request['redirect_uri'] === 'https://www.example.com/x/callback'
        && ! isset($request['client_id'])
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret')));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), X_ME_URL)
        && $request->hasHeader('Authorization', 'Bearer user-token'));

    CarbonImmutable::setTestNow();
});

it('sends the client id in the body for a public app without a secret', function () {
    config(['x-autopost.client_secret' => null]);
    Http::fake([
        X_TOKEN_URL => Http::response(['access_token' => 't', 'refresh_token' => 'r']),
        X_ME_URL.'*' => Http::response(['data' => ['username' => 'ada']]),
    ]);

    app(OAuthClient::class)->exchange('c', 'v');

    Http::assertSent(fn (Request $request) => $request->url() === X_TOKEN_URL
        && ! $request->hasHeader('Authorization'));
});

it('fails the exchange with X\'s error', function () {
    Http::fake([X_TOKEN_URL => Http::response([
        'error' => 'invalid_request',
        'error_description' => 'Missing valid code_verifier.',
    ], 400)]);

    expect(fn () => app(OAuthClient::class)->exchange('auth-code', 'v'))
        ->toThrow(XRequestFailed::class, 'Missing valid code_verifier.');
});

it('fails the exchange when X returns no access token', function () {
    Http::fake([X_TOKEN_URL => Http::response(['token_type' => 'bearer'])]);

    expect(fn () => app(OAuthClient::class)->exchange('c', 'v'))
        ->toThrow(XRequestFailed::class, 'no access token');
});

it('refreshes tokens and returns the rotated refresh token', function () {
    Http::fake([X_TOKEN_URL => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh'])]);

    expect(app(OAuthClient::class)->refresh('old-refresh'))
        ->toBe(['access_token' => 'new-access', 'refresh_token' => 'new-refresh']);

    Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

it('returns null when a refresh is refused or cannot be reached', function () {
    Http::fake([X_TOKEN_URL => Http::sequence()
        ->push(['error' => 'invalid_request'], 400)
        ->pushFailedConnection()]);

    $client = app(OAuthClient::class);

    expect($client->refresh('bad'))->toBeNull()
        ->and($client->refresh('bad'))->toBeNull();
});

it('reports the profile as active, expired or unknown', function () {
    Http::fake([X_ME_URL.'*' => Http::sequence()
        ->push(['data' => ['username' => 'ada', 'name' => 'Ada']])
        ->push(['title' => 'Unauthorized'], 401)
        ->push('down', 503)]);

    $client = app(OAuthClient::class);

    expect($client->profile('t'))->toMatchArray(['status' => 'active', 'user' => ['username' => 'ada', 'name' => 'Ada']])
        ->and($client->profile('t'))->toMatchArray(['status' => 'expired', 'user' => []])
        ->and($client->profile('t'))->toMatchArray(['status' => 'unknown', 'user' => []]);
});

it('revokes a token', function () {
    Http::fake(['https://api.x.com/2/oauth2/revoke' => Http::sequence()->push('', 200)->push(['error' => 'x'], 500)]);

    $client = app(OAuthClient::class);

    expect($client->revoke('t'))->toBeTrue()
        ->and($client->revoke('t'))->toBeFalse();

    Http::assertSent(fn (Request $request) => $request['token'] === 't'
        && $request['token_type_hint'] === 'access_token');
});
