<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests;

abstract class HeadlessTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('x-autopost.ui.enabled', false);
    }
}
