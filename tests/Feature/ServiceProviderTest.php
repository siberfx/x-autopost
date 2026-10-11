<?php

declare(strict_types=1);

it('merges the package config with safe defaults', function () {
    expect(config('x-autopost.autopost.enabled'))->toBeFalse()
        ->and(config('x-autopost.autopost.in_console'))->toBeFalse()
        ->and(config('x-autopost.notifications.enabled'))->toBeFalse()
        ->and(config('x-autopost.scopes'))->toBe(['tweet.read', 'tweet.write', 'users.read', 'offline.access'])
        ->and(config('x-autopost.routes.prefix'))->toBe('x');
});

it('creates the package tables', function () {
    expect(Schema::hasTable('x_connections'))->toBeTrue()
        ->and(Schema::hasTable('x_posts'))->toBeTrue();
});
