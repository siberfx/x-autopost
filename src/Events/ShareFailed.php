<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Events;

use Throwable;

/** An automatic share gave up after its last attempt. */
final class ShareFailed
{
    public function __construct(
        public readonly string $shareableType,
        public readonly int|string $shareableId,
        public readonly Throwable $exception,
    ) {}
}
