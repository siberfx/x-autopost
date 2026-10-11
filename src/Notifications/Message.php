<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Notifications;

use Illuminate\Database\Eloquent\Model;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Events\Shared;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Events\TokenExpired;
use Throwable;

/** Plain text a channel formats its own way: a headline and lines below it. Holds only strings, so it queues cleanly. */
final class Message
{
    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $level,
        public readonly string $headline,
        public readonly array $lines = [],
    ) {}

    public static function shared(Shared $event): self
    {
        [$title, $url] = self::describe($event->shareable);
        $id = $event->post->post_id;

        return new self(self::LEVEL_SUCCESS, 'Shared on X: '.$title, array_values(array_filter([
            $url,
            $id !== null ? 'Post: https://x.com/i/web/status/'.$id : null,
            'Trigger: '.$event->post->trigger,
        ])));
    }

    public static function failed(ShareFailed $event): self
    {
        return new self(self::LEVEL_ERROR, 'X share failed', [
            "Model: {$event->shareableType} #{$event->shareableId}",
            'Error: '.mb_substr($event->exception->getMessage(), 0, 500),
        ]);
    }

    public static function tokenExpired(TokenExpired $event): self
    {
        return new self(self::LEVEL_WARNING, 'X no longer accepts the connection', array_values(array_filter([
            $event->connection->username !== null ? 'Account: @'.$event->connection->username : null,
            'Connect again to keep posting.',
        ])));
    }

    public static function test(): self
    {
        return new self(self::LEVEL_INFO, 'X Autopost test notification', [
            'Notifications from '.config('app.name', 'Laravel').' reach this channel.',
        ]);
    }

    public function emoji(): string
    {
        return match ($this->level) {
            self::LEVEL_SUCCESS => '✅',
            self::LEVEL_ERROR => '❌',
            self::LEVEL_WARNING => '⚠️',
            default => 'ℹ️',
        };
    }

    /** @return array{0: string, 1: string|null} The title and URL the model posts, or its type and key. */
    private static function describe(Model $model): array
    {
        if ($model instanceof ShareableOnX) {
            try {
                $post = $model->toXPost();

                return [$post->title(), $post->url()];
            } catch (Throwable) {
                // Fall through: the post already went out, the message still should.
            }
        }

        return [$model->getMorphClass().' #'.$model->getKey(), null];
    }
}
