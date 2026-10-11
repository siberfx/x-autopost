<?php

declare(strict_types=1);

namespace Siberfx\XAutopost;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Siberfx\XAutopost\Contracts\TokenStore;
use Siberfx\XAutopost\Events\Shared;
use Siberfx\XAutopost\Events\ShareFailed;
use Siberfx\XAutopost\Events\TokenExpired;
use Siberfx\XAutopost\Listeners\SendNotifications;
use Siberfx\XAutopost\Support\Ui;

final class XAutopostServiceProvider extends ServiceProvider
{
    /** Who may connect, disconnect, see the connection and share. Define it in your app. */
    public const ABILITY = 'manage-x-autopost';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/x-autopost.php', 'x-autopost');

        $this->app->bind(
            TokenStore::class,
            fn ($app) => $app->make((string) config('x-autopost.token_store')),
        );

        $this->app->scoped(XManager::class);
    }

    public function boot(): void
    {
        // Deny by default. Registered once every provider has booted, so the
        // app's own definition wins whatever the provider order.
        $this->app->booted(function (): void {
            if (! Gate::has(self::ABILITY)) {
                Gate::define(self::ABILITY, fn ($user = null): bool => false);
            }
        });

        // Always listening; the listener reads x-autopost.notifications on each event.
        Event::listen([Shared::class, ShareFailed::class, TokenExpired::class], SendNotifications::class);

        if (Ui::enabled()) {
            $this->bootUi();
        }

        if (config('x-autopost.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/x-autopost.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/x-autopost.php' => config_path('x-autopost.php'),
            ], 'x-autopost-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'x-autopost-migrations');

            $this->publishes([
                __DIR__.'/../stubs/ShareableModel.stub' => base_path('stubs/x-autopost/ShareableModel.stub'),
            ], 'x-autopost-stubs');

            $this->commands([
                Console\InstallCommand::class,
                Console\StatusCommand::class,
                Console\DisconnectCommand::class,
                Console\ShareCommand::class,
                Console\CheckTokenCommand::class,
                Console\TestNotificationCommand::class,
            ]);
        }
    }

    /** Views, Blade and Livewire components; skipped in headless mode (ui.enabled = false). */
    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'x-autopost');
        Blade::componentNamespace('Siberfx\XAutopost\View\Components', 'x-autopost');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('x-autopost.connection', Livewire\ConnectionCard::class);
            \Livewire\Livewire::component('x-autopost.share-button', Livewire\ShareButton::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/x-autopost'),
            ], 'x-autopost-views');
        }
    }
}
