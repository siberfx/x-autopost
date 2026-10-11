<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Exceptions;

use RuntimeException;

final class NotConfigured extends RuntimeException
{
    public function __construct(string $message = 'Set X_CLIENT_ID and X_CLIENT_SECRET before connecting X.')
    {
        parent::__construct($message);
    }
}
