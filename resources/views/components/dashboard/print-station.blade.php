@props(['device', 'state'])

<a href="{{ route('devices.show', $device) }}" wire:navigate data-print-station="{{ $state->value }}" {{ $attributes->class('inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white py-1 pl-3 pr-1 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:hover:bg-zinc-700') }}>
    {{ $device->hostname }}
    <flux:badge size="sm" :color="$state->color()" :icon="$state->icon()" rounded>{{ $device->virtualPrinterLabel() }}</flux:badge>
</a>
