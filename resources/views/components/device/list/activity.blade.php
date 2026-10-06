@props(['inFlight'])

@if($inFlight->current())
    <button type="button" class="flex min-w-0 items-center gap-1 text-start text-xs text-sky-700 hover:underline dark:text-sky-300" wire:click="$dispatch('show-command', { commandId: {{ $inFlight->current()->id }} })" data-device-activity>
        <flux:icon.loading variant="micro" class="shrink-0" />
        <span class="truncate">{{ $inFlight->current()->activityLabel() }}@if($inFlight->behindCurrent() > 0) &middot; {{ $inFlight->behindCurrent() }} more @endif</span>
    </button>
@endif
