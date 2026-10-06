@props(['command' => null, 'action', 'icon' => 'play', 'variant' => null])

@if($command)
    <flux:button size="sm" :variant="$variant" icon="loading" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })" data-run-button-busy>
        @if($command->isPending())
            Queued...
        @else
            Running...@if($command->startedForHumans()) started {{ $command->startedForHumans() }}@endif
        @endif
    </flux:button>
@else
    <flux:button size="sm" :variant="$variant" :icon="$icon" wire:click="{{ $action }}" {{ $attributes }}>{{ $slot }}</flux:button>
@endif
