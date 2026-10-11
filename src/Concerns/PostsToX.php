<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Observers\ShareableObserver;

/** For models implementing ShareableOnX: auto-post and share history. */
trait PostsToX
{
    public static function bootPostsToX(): void
    {
        // Not static::observe(): that instantiates the model, which throws
        // while the model is still booting.
        static::saved(static fn ($model) => app(ShareableObserver::class)->saved($model));
    }

    /** @return MorphMany<XSharedPost, $this> */
    public function xPosts(): MorphMany
    {
        return $this->morphMany(XSharedPost::class, 'shareable');
    }

    public function wasPostedToX(): bool
    {
        return $this->xPosts()->where('status', XSharedPost::STATUS_POSTED)->exists();
    }
}
