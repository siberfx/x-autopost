<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Siberfx\XAutopost\Contracts\ShareableOnX;
use Siberfx\XAutopost\Models\XSharedPost;
use Siberfx\XAutopost\XAutopostServiceProvider;
use Siberfx\XAutopost\XManager;

final class ShareButtonPresenter
{
    private ?CarbonImmutable $lastPostedAt = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Model&ShareableOnX $model,
        private readonly XManager $x,
        private readonly ?string $label = null,
    ) {}

    public function lastPostedAt(): ?CarbonImmutable
    {
        if (! $this->resolved) {
            $this->lastPostedAt = XSharedPost::query()->for($this->model)->posted()->latest('posted_at')->first()?->posted_at;
            $this->resolved = true;
        }

        return $this->lastPostedAt;
    }

    public function disabledReason(): ?string
    {
        if (Gate::denies(XAutopostServiceProvider::ABILITY)) {
            return 'You are not allowed to manage X.';
        }

        if (! $this->x->isConnected()) {
            return 'Connect X first.';
        }

        return $this->model->isLiveForX() ? null : 'Only live items can be shared.';
    }

    public function text(): string
    {
        return $this->label ?? ($this->lastPostedAt() !== null ? 'Share again' : 'Share on X');
    }

    public function title(): string
    {
        $posted = $this->lastPostedAt();

        return $this->disabledReason()
            ?? ($posted !== null ? 'Shared on X on '.$posted->format('j M Y').'. Share again.' : 'Share on X');
    }

    public function confirmText(): string
    {
        $posted = $this->lastPostedAt();

        return $posted !== null
            ? 'This was already posted on '.$posted->format('j M Y').'. Post it to X again?'
            : 'Post this to X now?';
    }

    public function signedAction(): string
    {
        return URL::signedRoute(config('x-autopost.routes.name', 'x-autopost.').'share.signed', [
            'type' => $this->model->getMorphClass(),
            'id' => $this->model->getKey(),
        ]);
    }
}
