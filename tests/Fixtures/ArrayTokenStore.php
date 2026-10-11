<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests\Fixtures;

use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Data\StoredConnection;

final class ArrayTokenStore implements TokenStore
{
    public static ?StoredConnection $connection = null;

    public function get(): ?StoredConnection
    {
        return self::$connection;
    }

    public function put(StoredConnection $connection): void
    {
        self::$connection = $connection;
    }

    public function forget(): void
    {
        self::$connection = null;
    }
}
