<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Events;

use Siberfx\XAutopost\Data\Connection;

/** Fired by x:check-token when X no longer accepts the connection: it must be connected again. */
final class TokenExpired
{
    public function __construct(
        public readonly Connection $connection,
    ) {}
}
