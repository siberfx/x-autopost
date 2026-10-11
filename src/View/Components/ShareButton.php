<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use InvalidArgumentException;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Support\ShareButtonPresenter;
use Siberfx\XAutopost\Support\Theme;
use Siberfx\XAutopost\XManager;

final class ShareButton extends Component
{
    public function __construct(
        public Model $model,
        public ?string $label = null,
        public string $size = 'md',
        public bool $confirm = true,
    ) {
        if (! $model instanceof ShareableOnX) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnX::class.'.');
        }
    }

    public function render(): View
    {
        /** @var Model&ShareableOnX $model */
        $model = $this->model;

        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($model, app(XManager::class), $this->label),
            'size' => $this->size === 'sm' ? 'sm' : 'md',
            'confirm' => $this->confirm,
            'livewire' => false,
            'flash' => null,
        ]);
    }
}
