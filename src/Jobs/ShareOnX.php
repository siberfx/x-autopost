<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Exceptions\NotConnected;
use Siberfx\XAutopost\Exceptions\NotShareable;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\XManager;
use Throwable;

final class ShareOnX implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    /** Seconds the unique lock may outlive a job that never ran. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $shareableType,
        public readonly int|string $shareableId,
    ) {
        $this->tries = (int) config('x-autopost.job.tries', 3);
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_values(array_map('intval', (array) config('x-autopost.job.backoff', [60, 300])));
    }

    public function handle(XManager $x): void
    {
        $model = $this->resolve();

        // Unpublished, deleted, disconnected or already posted since the
        // job was queued: nothing to do.
        if ($model === null || ! $model->isLiveForX() || ! $x->isConnected() || $x->wasPosted($model)) {
            return;
        }

        try {
            $x->share($model, XSharedPost::TRIGGER_AUTO);
        } catch (XRequestFailed $e) {
            // 429, 5xx and network errors (status 0) are retried; other 4xx never succeed.
            if (! $e->isPermanent()) {
                throw $e;
            }

            $this->fail($e);
        } catch (InvalidArgumentException|NotShareable|NotConnected $e) {
            $this->fail($e);
        }
    }

    /** One queued share per model: quick publish/unpublish/publish cycles cannot queue two posts. */
    public function uniqueId(): string
    {
        return "{$this->shareableType}:{$this->shareableId}";
    }

    public function failed(Throwable $exception): void
    {
        XSharedPost::query()->create([
            'shareable_type' => $this->shareableType,
            'shareable_id' => $this->shareableId,
            'status' => XSharedPost::STATUS_FAILED,
            'trigger' => XSharedPost::TRIGGER_AUTO,
            'error' => mb_substr($exception->getMessage(), 0, 2000),
            'posted_at' => null,
            'created_at' => CarbonImmutable::now(),
        ]);

        event(new ShareFailed($this->shareableType, $this->shareableId, $exception));
    }

    private function resolve(): (Model&ShareableOnX)|null
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnX::class)) {
            return null;
        }

        /** @var (Model&ShareableOnX)|null */
        return $class::query()->find($this->shareableId);
    }
}
