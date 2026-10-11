<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Stores\DatabaseTokenStore;

function storedConnection(string $token = 'member-token'): StoredConnection
{
    return new StoredConnection(
        accessToken: $token,
        refreshToken: 'refresh-1',
        username: 'ada',
        name: 'Ada Lovelace',
        picture: 'https://pbs.twimg.com/a.jpg',
        scopes: ['openid', 'w_member_social'],
        connectedAt: CarbonImmutable::parse('2026-10-02 10:00:00'),
    );
}

it('is the default TokenStore binding', function () {
    expect(app(TokenStore::class))->toBeInstanceOf(DatabaseTokenStore::class);
});

it('returns null when nothing is stored', function () {
    expect(app(TokenStore::class)->get())->toBeNull();
});

it('round-trips a connection', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection());

    $back = $store->get();

    expect($back)->toBeInstanceOf(StoredConnection::class)
        ->and($back->accessToken)->toBe('member-token')
        ->and($back->username)->toBe('ada')
        ->and($back->name)->toBe('Ada Lovelace')
        ->and($back->scopes)->toBe(['openid', 'w_member_social'])
        ->and($back->refreshToken)->toBe('refresh-1');
});

it('encrypts the token at rest', function () {
    app(TokenStore::class)->put(storedConnection('plain-secret-token'));

    $raw = DB::table('x_connections')->value('access_token');

    expect($raw)->not->toContain('plain-secret-token');
});

it('keeps a single row when connecting again', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection('first'));
    $store->put(storedConnection('second'));

    expect(DB::table('x_connections')->count())->toBe(1)
        ->and($store->get()?->accessToken)->toBe('second');
});

it('forgets the connection', function () {
    $store = app(TokenStore::class);
    $store->put(storedConnection());
    $store->forget();

    expect($store->get())->toBeNull();
});

// As after APP_KEY was rotated since connecting.
it('treats an undecryptable token as not connected', function () {
    app(TokenStore::class)->put(storedConnection());

    // Same effect as a new APP_KEY: Eloquent decrypts with a different key.
    Model::encryptUsing(new Encrypter(str_repeat('z', 32), 'AES-256-CBC'));

    try {
        expect(app(TokenStore::class)->get())->toBeNull();
    } finally {
        Model::encryptUsing(null);
    }
});
