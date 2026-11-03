@props(['row', 'muted' => false])

@php($device = $row->attention->device)

<div {{ $attributes->class('flex h-8 items-center gap-3') }}>
    <span class="size-1.5 shrink-0 rounded-[1px] {{ $row->attention->severity()->squareClass() }}" data-attention-severity="{{ $row->attention->severity()->value }}" title="{{ $device->statusLabel() }}"></span>
    <a href="{{ route('devices.show', $device) }}" wire:navigate @class(['w-40 shrink-0 truncate text-sm font-medium hover:underline', 'text-zinc-800 dark:text-zinc-100' => ! $muted, 'text-zinc-500 dark:text-zinc-400' => $muted])>{{ $device->hostname }}</a>
    <div class="min-w-0 flex-1 truncate font-mono text-xs">
        @if($device->isMonitorOnly)
            <span class="text-zinc-400" data-monitor-only title="Monitor only">mon</span><span class="text-zinc-300 dark:text-zinc-600"> · </span>
        @endif
        @foreach($row->chips as $chip)
            <span class="{{ $chip->textClass() }}" title="{{ $chip->detail }}" data-attention-chip>{{ $chip->label }}</span>@unless($loop->last)<span class="text-zinc-300 dark:text-zinc-600"> · </span>@endunless
        @endforeach
    </div>
    <span class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">{{ $device->lastSeenForHumans() }}</span>
</div>
