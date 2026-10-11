@php
    $reason = $presenter->disabledReason();
    $classes = ($size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3 py-2 text-sm')
        .' inline-flex items-center gap-1.5 rounded-lg border border-gray-300 font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800';
@endphp
<span class="inline-flex flex-col items-start gap-1">
    @if ($livewire)
        <button type="button" wire:click="share" @if ($confirm && ! $reason) wire:confirm="{{ $presenter->confirmText() }}" @endif
                @disabled($reason) title="{{ $presenter->title() }}" wire:loading.attr="disabled" class="{{ $classes }}">
            <svg class="size-4 text-gray-900 dark:text-gray-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            <span>{{ $presenter->text() }}</span>
        </button>
    @else
        <form method="POST" action="{{ $presenter->signedAction() }}" class="inline" @if ($confirm && ! $reason) onsubmit="return confirm(@js($presenter->confirmText()))" @endif>
            @csrf
            <button type="submit" @disabled($reason) title="{{ $presenter->title() }}" class="{{ $classes }}">
                <svg class="size-4 text-gray-900 dark:text-gray-100" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                <span>{{ $presenter->text() }}</span>
            </button>
        </form>
    @endif
    @if ($reason)
        <span class="sr-only">{{ $reason }}</span>
    @endif
    @if ($flash)
        <span class="text-xs {{ $flash['type'] === 'error' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $flash['message'] }}</span>
    @endif
</span>
