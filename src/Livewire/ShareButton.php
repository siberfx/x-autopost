<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Exceptions\NotConnected;
use Siberfx\XAutopost\Exceptions\NotShareable;
use Siberfx\XAutopost\Exceptions\XRequestFailed;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\Support\ShareButtonPresenter;
use Siberfx\XAutopost\Support\Theme;
use Siberfx\XAutopost\XAutopostServiceProvider;
use Siberfx\XAutopost\XManager;

final class ShareButton extends Component
{
    #[Locked]
    public string $shareableType = '';

    #[Locked]
    public string $shareableId = '';

    public ?string $label = null;

    public string $size = 'md';

    public bool $confirm = true;

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(Model $model, ?string $label = null, string $size = 'md', bool $confirm = true): void
    {
        if (! $model instanceof ShareableOnX) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnX::class.'.');
        }

        $this->shareableType = $model->getMorphClass();
        $this->shareableId = (string) $model->getKey();
        $this->label = $label;
        $this->size = $size === 'sm' ? 'sm' : 'md';
        $this->confirm = $confirm;
    }

    public function share(XManager $x): void
    {
        Gate::authorize(XAutopostServiceProvider::ABILITY);

        try {
            $post = $x->share($this->model(), XSharedPost::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable|XRequestFailed|InvalidArgumentException $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];

            return;
        }

        $this->flash = ['type' => 'success', 'message' => 'Shared on X.'];
        $this->dispatch('x-autopost-shared', id: $post->post_id);
    }

    public function render(XManager $x): View
    {
        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($this->model(), $x, $this->label),
            'size' => $this->size,
            'confirm' => $this->confirm,
            'livewire' => true,
            'flash' => $this->flash,
        ]);
    }

    private function model(): Model&ShareableOnX
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        abort_if(! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnX::class), 404);

        /** @var Model&ShareableOnX */
        return $class::query()->findOrFail($this->shareableId);
    }
}
