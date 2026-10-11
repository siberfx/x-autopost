@php
    $badge = $presenter->badge();
    $tone = ['success' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger', 'neutral' => 'text-bg-secondary'][$badge['tone']];
    $confirmText = 'Disconnect X? The token is revoked and nothing posts until you connect again.';
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('card') }}>
    <div class="card-header d-flex align-items-center gap-2 fw-semibold">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        X
    </div>
    <div class="card-body">
        @include('x-autopost::bootstrap.flash', ['flash' => $flash])

        @if (! $connection->connected)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <div class="fw-medium">Not connected</div>
                    <div class="text-body-secondary small">Connect a X account to share your content.</div>
                </div>
                @if ($canManage)
                    <a href="{{ $connectUrl }}" class="btn btn-primary">Connect X</a>
                @endif
            </div>
        @else
            <div class="d-flex flex-wrap align-items-center gap-3">
                @if ($connection->picture)
                    <img src="{{ $connection->picture }}" alt="" width="48" height="48" class="rounded-circle object-fit-cover" referrerpolicy="no-referrer">
                @else
                    <span aria-hidden="true" class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold" style="width:48px;height:48px">{{ $presenter->initials() }}</span>
                @endif

                <div class="flex-grow-1" style="min-width:0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold text-truncate">{{ $presenter->displayName() }}</span>
                        <span class="badge {{ $tone }}">{{ $badge['label'] }}</span>
                    </div>
                    @if ($presenter->handle())
                        <div class="text-body-secondary small text-truncate">{{ $presenter->handle() }}</div>
                    @endif
                    <div class="text-body-secondary small mt-1">
                        @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                    </div>
                </div>

                @if ($canManage)
                <div class="d-flex gap-2">
                    <a href="{{ $connectUrl }}" class="btn {{ $presenter->needsReconnect() ? 'btn-primary' : 'btn-outline-secondary' }}">Reconnect</a>
                    @if ($livewire)
                        <button type="button" class="btn btn-outline-danger" wire:click="disconnect" wire:confirm="{{ $confirmText }}">Disconnect</button>
                    @else
                        <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                        </form>
                    @endif
                </div>
                @endif
            </div>

            @if ($connection->status === 'unknown')
                <div class="text-body-secondary small mt-2">X could not be asked about this token just now; it may still work.</div>
            @endif
        @endif
    </div>
</div>
