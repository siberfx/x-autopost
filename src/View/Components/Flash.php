<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Siberfx\XAutopost\Support\StatusMessages;
use Siberfx\XAutopost\Support\Theme;

final class Flash extends Component
{
    public function render(): View
    {
        return view(Theme::view('flash'), ['flash' => StatusMessages::current(request())]);
    }
}
