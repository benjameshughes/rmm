@props(['command', 'variant' => null, 'size' => 'sm', 'label' => null])

<flux:button :size="$size" :variant="$variant" icon="loading" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })" {{ $attributes }} data-run-button-busy>
    @if($command->packageAction())
        {{ $command->isPending() ? 'Queued: '.$command->packageAction()->label() : $command->packageAction()->inProgressLabel().'...' }}
    @elseif($command->isPending())
        Queued...
    @else
        {{ $label ?? 'Running...' }}
    @endif
</flux:button>
