<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Siberfx\XAutopost\Support\ConnectionPresenter;
use Siberfx\XAutopost\Support\StatusMessages;
use Siberfx\XAutopost\Support\Theme;
use Siberfx\XAutopost\XAutopostServiceProvider;
use Siberfx\XAutopost\XManager;

final class Connection extends Component
{
    public function render(): View
    {
        $connection = app(XManager::class)->connection();
        $routes = (string) config('x-autopost.routes.name', 'x-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => StatusMessages::current(request()),
            'livewire' => false,
            'canManage' => Gate::allows(XAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
