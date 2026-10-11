<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests;

use Siberfx\XAutopost\Tests\Fixtures\AppGateServiceProvider;

/**
 * The app defines the gate in a provider that boots before the package's,
 * as an app provider listed earlier would.
 */
abstract class AppGateTestCase extends DefaultGateTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AppGateServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
