<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Component;
use Siberfx\XAutopost\Support\ConnectionPresenter;
use Siberfx\XAutopost\Support\StatusMessages;
use Siberfx\XAutopost\Support\Theme;
use Siberfx\XAutopost\XAutopostServiceProvider;
use Siberfx\XAutopost\XManager;

final class ConnectionCard extends Component
{
    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(): void
    {
        $this->flash = StatusMessages::current(request());
    }

    public function disconnect(XManager $x): void
    {
        Gate::authorize(XAutopostServiceProvider::ABILITY);

        $revoked = $x->disconnect();

        $this->flash = ['type' => 'success', 'message' => $revoked
            ? 'X disconnected.'
            : 'X disconnected here, but X did not confirm revoking the token.'];
    }

    public function render(XManager $x): View
    {
        $connection = $x->connection();
        $routes = (string) config('x-autopost.routes.name', 'x-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => $this->flash,
            'livewire' => true,
            'attributes' => new ComponentAttributeBag,
            'canManage' => Gate::allows(XAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
