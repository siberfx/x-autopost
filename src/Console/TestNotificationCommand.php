<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\XAutopost\Notifications\Message;
use Siberfx\XAutopost\Notifications\Notifier;
use Throwable;

final class TestNotificationCommand extends Command
{
    protected $signature = 'x:test-notification {--channel=* : Only these channels (default: every enabled one)}';

    protected $description = 'Send a test message to the enabled X notification channels (Telegram, Slack…)';

    public function handle(Notifier $notifier): int
    {
        /** @var list<string> $only */
        $only = (array) $this->option('channel');
        $channels = $only !== [] ? $only : $notifier->enabledChannels();

        if ($channels === []) {
            $this->components->warn('No notification channel is enabled. Set X_NOTIFY_TELEGRAM=true or X_NOTIFY_SLACK=true.');

            return self::FAILURE;
        }

        if (! $notifier->enabled()) {
            $this->components->warn('Notifications are off (X_NOTIFY=false); sending the test anyway.');
        }

        $failed = false;

        foreach ($channels as $name) {
            try {
                $channel = $notifier->channel($name);

                if (! $channel->configured()) {
                    $this->components->error("{$name}: missing settings, see notifications.channels.{$name} in config/x-autopost.php.");
                    $failed = true;

                    continue;
                }

                $channel->send(Message::test());
                $this->components->info("{$name}: sent.");
            } catch (Throwable $e) {
                $this->components->error("{$name}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
