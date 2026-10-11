<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Observers;

use Illuminate\Database\Eloquent\Model;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\XManager;

final class ShareableObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof ShareableOnX || ! config('x-autopost.autopost.enabled')) {
            return;
        }

        if (app()->runningInConsole() && ! config('x-autopost.autopost.in_console')) {
            return;
        }

        if (! $model->isLiveForX() || ! $this->justBecameLive($model)) {
            return;
        }

        $x = app(XManager::class);

        if (! $x->isConnected() || $x->wasPosted($model)) {
            return;
        }

        $x->queue($model);
    }

    /**
     * Inside "saved" the original attributes still hold the values from
     * before this save. Evaluate the model's own rule on them, so any live
     * rule (enum, boolean, dates) works without naming columns.
     */
    private function justBecameLive(Model&ShareableOnX $model): bool
    {
        $before = $model->getRawOriginal();

        if ($before === []) {
            return true; // a fresh insert
        }

        $previous = (clone $model)->setRawAttributes($before);

        return ! $previous->isLiveForX();
    }
}
