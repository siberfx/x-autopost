<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\XAutopost\XManager;

final class StatusCommand extends Command
{
    protected $signature = 'x:status';

    protected $description = 'Show the connected X account and its connection status';

    public function handle(XManager $x): int
    {
        $connection = $x->connection();

        if (! $connection->connected) {
            $this->components->warn('Not connected to X.');

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Name', $connection->name ?? '—'],
            ['Username', $connection->username !== null ? '@'.$connection->username : '—'],
            ['Status', $connection->status ?? '—'],
            ['Scopes', implode(', ', $connection->scopes) ?: '—'],
            ['Connected', $connection->connectedAt?->toDateTimeString() ?? '—'],
        ]);

        return self::SUCCESS;
    }
}
