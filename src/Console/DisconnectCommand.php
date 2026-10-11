<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\XAutopost\XManager;

final class DisconnectCommand extends Command
{
    protected $signature = 'x:disconnect {--force : Do not ask for confirmation}';

    protected $description = 'Revoke and forget the X connection';

    public function handle(XManager $x): int
    {
        if (! $x->isConnected()) {
            $this->components->info('Not connected to X.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Disconnect X and revoke the token?')) {
            return self::FAILURE;
        }

        $revoked = $x->disconnect();

        $revoked
            ? $this->components->info('Disconnected. X revoked the token.')
            : $this->components->warn('Disconnected here, but X did not confirm revoking the token.');

        return self::SUCCESS;
    }
}
