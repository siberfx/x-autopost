<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests\Fixtures;

use Siberfx\XAutopost\Contracts\NotificationChannel;
use Siberfx\XAutopost\Notifications\Message;

final class RecordingChannel implements NotificationChannel
{
    /** @var list<string> */
    public static array $sent = [];

    /** @param  array<string, mixed>  $config */
    public function __construct(private readonly array $config = []) {}

    public function configured(): bool
    {
        return true;
    }

    public function send(Message $message): void
    {
        self::$sent[] = $this->config['prefix'].' '.$message->headline;
    }
}
