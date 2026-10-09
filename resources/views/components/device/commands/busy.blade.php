@props(['command', 'variant' => null, 'size' => 'sm', 'label' => null])

@php($percentLabel = $command->liveProgress()?->percentLabel())

<flux:button :size="$size" :variant="$variant" icon="loading" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })" {{ $attributes }} data-run-button-busy>
    @if($command->packageAction())
        {{ $command->isPending() ? 'Queued: '.$command->packageAction()->label() : $command->packageAction()->inProgressLabel().'...' }}
    @elseif($command->isPending())
        Queued...
    @else
        {{ $label ?? 'Running...' }}
    @endif
    @if($percentLabel)
        <span class="tabular-nums" data-run-button-percent>{{ $percentLabel }}</span>
    @endif
</flux:button>
