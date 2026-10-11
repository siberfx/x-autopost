<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;
use Siberfx\XAutopost\Models\XConnection;

final class DatabaseTokenStore implements TokenStore
{
    public function get(): ?StoredConnection
    {
        $row = XConnection::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        try {
            $token = $row->access_token;
            $refresh = $row->refresh_token;
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown (thrown by the encrypted cast on property access)
            // APP_KEY changed since connecting: the tokens are unreadable, so
            // the app is effectively disconnected and must connect again.
            return null;
        }

        if ($token === '') {
            return null;
        }

        return new StoredConnection(
            accessToken: $token,
            refreshToken: $refresh !== '' ? $refresh : null,
            username: $row->username,
            name: $row->name,
            picture: $row->picture,
            scopes: array_values($row->scopes ?? []), // @phpstan-ignore arrayValues.list (stored JSON is not guaranteed to be a list)
            connectedAt: $row->connected_at,
        );
    }

    public function put(StoredConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            XConnection::query()->delete();

            XConnection::query()->create([
                'access_token' => $connection->accessToken,
                'refresh_token' => $connection->refreshToken,
                'username' => $connection->username,
                'name' => $connection->name,
                'picture' => $connection->picture,
                'scopes' => $connection->scopes,
                'connected_at' => $connection->connectedAt,
            ]);
        });
    }

    public function forget(): void
    {
        XConnection::query()->delete();
    }
}
