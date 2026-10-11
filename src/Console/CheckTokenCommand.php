<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\XAutopost\Events\TokenExpired;
use Siberfx\XAutopost\XManager;

final class CheckTokenCommand extends Command
{
    protected $signature = 'x:check-token';

    protected $description = 'Fire TokenExpired when X no longer accepts the connection';

    public function handle(XManager $x): int
    {
        $connection = $x->connection();

        if (! $connection->connected) {
            $this->components->info('Not connected to X.');

            return self::SUCCESS;
        }

        if (in_array($connection->status, ['expired', 'revoked'], true)) {
            event(new TokenExpired($connection));
            $this->components->warn('X no longer accepts the connection. Connect again to keep posting.');

            return self::SUCCESS;
        }

        $this->components->info($connection->status === 'active'
            ? 'The X connection is active.'
            : 'X could not be asked; the connection status is unknown.');

        return self::SUCCESS;
    }
}
