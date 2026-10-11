<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Listeners;

use Siberfx\XAutopost\Events\Shared;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Events\TokenExpired;
use Siberfx\XAutopost\Notifications\Message;
use Siberfx\XAutopost\Notifications\Notifier;
use Throwable;

/** Turns the package events into Telegram/Slack messages when x-autopost.notifications says so. */
final class SendNotifications
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Shared|ShareFailed|TokenExpired $event): void
    {
        $key = match (true) {
            $event instanceof Shared => 'shared',
            $event instanceof ShareFailed => 'failed',
            $event instanceof TokenExpired => 'token_expired',
        };

        if (! $this->notifier->wants($key)) {
            return;
        }

        try {
            $message = match (true) {
                $event instanceof Shared => Message::shared($event),
                $event instanceof ShareFailed => Message::failed($event),
                $event instanceof TokenExpired => Message::tokenExpired($event),
            };
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->notifier->notify($message);
    }
}
