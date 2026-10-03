@props(['device', 'disk'])

<a href="{{ route('devices.show', $device) }}" wire:navigate {{ $attributes->class('grid grid-cols-[minmax(0,10rem)_3.5rem_1fr_3.5rem] items-center gap-3 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5') }}>
    <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $device->hostname }}</span>
    <span class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $disk['name'] }}</span>
    <x-device.usage-bar :percent="$disk['usedPercent']" :color="$disk['barColor']" title="{{ $disk['freeForHumans'] }}" />
    <span class="text-right text-sm tabular-nums text-zinc-800 dark:text-white">{{ $disk['usedForHumans'] }}</span>
</a>
