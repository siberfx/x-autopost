<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Events;

use Illuminate\Database\Eloquent\Model;
use Siberfx\XAutopost\Models\XSharedPost;

final class Shared
{
    public function __construct(
        public readonly Model $shareable,
        public readonly XSharedPost $post,
    ) {}
}
