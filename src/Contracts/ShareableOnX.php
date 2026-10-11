<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Contracts;

use Siberfx\XAutopost\Posts\XPost;

/** Implemented by Eloquent models that can be shared on X. */
interface ShareableOnX
{
    /** Whether the model is public right now (e.g. published and not expired). */
    public function isLiveForX(): bool;

    /** @throws \InvalidArgumentException When XPost::make() is given an invalid URL. */
    public function toXPost(): XPost;
}
