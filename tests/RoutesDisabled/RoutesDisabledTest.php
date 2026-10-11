<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Siberfx\XAutopost\Tests\RoutesDisabledTestCase;

uses(RoutesDisabledTestCase::class);

it('registers no routes when disabled', function () {
    expect(app('router')->has('x-autopost.callback'))->toBeFalse()
        ->and(app('router')->has('x-autopost.share'))->toBeFalse();
});

it('installs without the callback route and says to set the redirect URI', function () {
    $envDir = sys_get_temp_dir().'/x-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('x:install', ['--theme' => 'tailwind'])
            ->expectsOutputToContain('Set X_REDIRECT_URI to the URL you registered')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('x-autopost.php'));
        foreach (File::glob(database_path('migrations/*x_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
