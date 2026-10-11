<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Events;

use Siberfx\XAutopost\Data\Connection;

final class Connected
{
    public function __construct(public readonly Connection $connection) {}
}
