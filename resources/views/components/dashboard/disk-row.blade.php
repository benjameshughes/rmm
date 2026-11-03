@props(['device', 'disk', 'meterColor'])

<a href="{{ route('devices.show', $device) }}" wire:navigate {{ $attributes->class('grid grid-cols-[minmax(0,1fr)_2.5rem_4rem_3rem] items-center gap-2 py-1.5') }}>
    <span class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-zinc-100">{{ $device->hostname }}</span>
    <span class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $disk['name'] }}</span>
    <x-device.usage-bar :percent="$disk['usedPercent']" :color="$meterColor" thin title="{{ $disk['freeForHumans'] }}" />
    <span class="text-right text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ $disk['usedForHumans'] }}</span>
</a>
