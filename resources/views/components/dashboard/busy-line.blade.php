@props(['row'])

<a href="{{ route('devices.metrics', $row->device) }}" wire:navigate {{ $attributes->class('grid grid-cols-[minmax(0,1fr)_4rem_2.5rem_2.5rem] items-center gap-2 py-1.5') }}>
    <span class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-zinc-100">{{ $row->device->hostname }}</span>
    <x-dashboard.sparkline :points="$row->sparkline" field="cpu" class="!h-4" />
    <span class="text-right text-xs font-medium tabular-nums {{ $row->cpuLevel->textClass() }}">{{ $row->cpu }}</span>
    <span class="text-right text-xs font-medium tabular-nums {{ $row->ramLevel->textClass() }}">{{ $row->ram }}</span>
</a>
