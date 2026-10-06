@props(['inFlight'])

@unless($inFlight->isEmpty())
    <div {{ $attributes->class('flex flex-col gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-sky-500/30 dark:bg-sky-500/10') }} data-in-flight>
        @if($inFlight->running)
            <button type="button" class="flex min-w-0 items-center gap-2 text-start" wire:click="$dispatch('show-command', { commandId: {{ $inFlight->running->id }} })" data-in-flight-running>
                <flux:icon.loading variant="micro" class="shrink-0 text-sky-600 dark:text-sky-400" />
                <flux:text size="sm" class="truncate text-zinc-800 dark:text-white">
                    Running <span class="font-medium">{{ $inFlight->running->displayName() }}</span>
                    @if($inFlight->running->startedForHumans())
                        &middot; started {{ $inFlight->running->startedForHumans() }}
                    @endif
                </flux:text>
            </button>
        @endif

        @if($inFlight->nextPending())
            <div class="flex min-w-0 flex-wrap items-center gap-2" data-in-flight-pending>
                <flux:icon name="queue-list" variant="micro" class="shrink-0 text-sky-600 dark:text-sky-400" />
                <flux:text size="sm" class="font-medium text-zinc-800 dark:text-white">{{ $inFlight->queuedForHumans() }}</flux:text>
                <button type="button" class="min-w-0 truncate text-start text-sm text-zinc-600 hover:underline dark:text-zinc-300" wire:click="$dispatch('show-command', { commandId: {{ $inFlight->nextPending()->id }} })">
                    next: {{ $inFlight->nextPending()->displayName() }}
                </button>
                @if($inFlight->isWaitingForWake)
                    <flux:badge size="sm" color="amber" icon="moon">waiting for the device to wake</flux:badge>
                @endif
                @can('cancel', $inFlight->nextPending())
                    <flux:button size="sm" variant="ghost" icon="x-circle" x-on:click="$dispatch('confirm-action', { heading: 'Cancel command', message: 'Cancel this command before it runs?', confirm: 'Cancel command', danger: true, action: () => $wire.cancelCommand({{ $inFlight->nextPending()->id }}) })">Cancel</flux:button>
                @endcan
            </div>
        @endif
    </div>
@endunless
