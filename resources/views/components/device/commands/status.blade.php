@props(['command'])

<div {{ $attributes->class('flex items-center gap-2') }}>
    <flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge>
    @can('cancel', $command)
        <flux:button size="sm" variant="ghost" icon="x-circle" x-on:click.stop="$dispatch('confirm-action', { heading: 'Cancel command', message: 'Cancel this command before it runs?', confirm: 'Cancel command', danger: true, action: () => $wire.cancelCommand({{ $command->id }}) })">Cancel</flux:button>
    @endcan
</div>
