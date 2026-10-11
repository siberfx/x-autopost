<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Support;

final class Theme
{
    public static function name(): string
    {
        $theme = config('x-autopost.ui.theme', 'tailwind');

        return is_string($theme) && $theme !== '' ? $theme : 'tailwind';
    }

    /** @return view-string */
    public static function view(string $name): string
    {
        /** @var view-string $view */
        $view = sprintf('x-autopost::%s.%s', self::name(), $name);

        return $view;
    }
}
