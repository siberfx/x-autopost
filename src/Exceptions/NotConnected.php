<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Exceptions;

use RuntimeException;

final class NotConnected extends RuntimeException
{
    public function __construct(string $message = 'X is not connected.')
    {
        parent::__construct($message);
    }
}
