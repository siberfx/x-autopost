<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Support;

final class Ui
{
    /** False in headless mode: no views or components, JSON-only responses. */
    public static function enabled(): bool
    {
        return (bool) config('x-autopost.ui.enabled', true);
    }
}
