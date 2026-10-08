@props(['command', 'variant' => null])

<flux:button size="sm" :variant="$variant" icon="loading" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })" data-run-button-busy>
    @if($command->packageAction())
        {{ $command->isPending() ? 'Queued: '.$command->packageAction()->label() : $command->packageAction()->inProgressLabel().'...' }}
    @elseif($command->isPending())
        Queued...
    @else
        Running...
    @endif
</flux:button>
