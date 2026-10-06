@props(['command' => null, 'action', 'icon' => 'play', 'variant' => null])

@if($command)
    <x-device.commands.busy :command="$command" :variant="$variant" />
@else
    <flux:button size="sm" :variant="$variant" :icon="$icon" wire:click="{{ $action }}" {{ $attributes }}>{{ $slot }}</flux:button>
@endif
