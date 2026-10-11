<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests;

abstract class RoutesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('x-autopost.routes.enabled', false);
    }
}
