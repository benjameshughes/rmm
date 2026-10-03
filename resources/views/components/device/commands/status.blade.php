@props(['command'])

<div {{ $attributes->class('flex items-center gap-2') }}>
    <flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge>
    @can('cancel', $command)
        <flux:button size="sm" variant="ghost" icon="x-circle" wire:click.stop="cancelCommand({{ $command->id }})" wire:confirm="Cancel this command before it runs?">Cancel</flux:button>
    @endcan
</div>
