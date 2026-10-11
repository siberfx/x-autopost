<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Events;

final class Disconnected
{
    /** @param  bool  $revoked  Whether X confirmed revoking the token. */
    public function __construct(public readonly bool $revoked) {}
}
