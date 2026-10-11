<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Contracts;

use Siberfx\XAutopost\Data\StoredConnection;

interface TokenStore
{
    /** The stored connection, or null when not connected (or unreadable). */
    public function get(): ?StoredConnection;

    /** Replace any stored connection with this one. */
    public function put(StoredConnection $connection): void;

    public function forget(): void;
}
