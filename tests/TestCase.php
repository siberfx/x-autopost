<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\XAutopost\Facades\X;
use Siberfx\XAutopost\Tests\Fixtures\Post;
use Siberfx\XAutopost\XAutopostServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Relation::morphMap(['post' => Post::class]);

        $this->defineManageGate();
    }

    /** The package denies everyone by default; most tests act as an allowed admin. */
    protected function defineManageGate(): void
    {
        Gate::define(XAutopostServiceProvider::ABILITY, fn ($user = null) => true);
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            class_exists(LivewireServiceProvider::class) ? LivewireServiceProvider::class : null,
            XAutopostServiceProvider::class,
        ]));
    }

    protected function getPackageAliases($app): array
    {
        return ['X' => X::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('x-autopost.client_id', 'client-id');
        $app['config']->set('x-autopost.client_secret', 'client-secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /** Laravel caches runningInConsole(); tests run in the CLI, so flip it to exercise the web path. */
    protected function pretendNotInConsole(): void
    {
        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }
}
