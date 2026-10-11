<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Contracts;

use Siberfx\XAutopost\Exceptions\NotificationFailed;
use Siberfx\XAutopost\Notifications\Message;

/**
 * Somewhere to send notifications (Telegram, Slack, your own). Built by the
 * container with the channel's config array as the `$config` argument.
 */
interface NotificationChannel
{
    /** Whether the settings the channel needs (token, chat, webhook…) are present. */
    public function configured(): bool;

    /** @throws NotificationFailed */
    public function send(Message $message): void;
}
