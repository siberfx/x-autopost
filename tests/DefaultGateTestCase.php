<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests;

/** Keeps the package's own deny-by-default gate. */
abstract class DefaultGateTestCase extends TestCase
{
    protected function defineManageGate(): void {}
}
