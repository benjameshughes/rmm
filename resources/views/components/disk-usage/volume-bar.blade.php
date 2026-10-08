@props(['scan'])

@php($unaccounted = $scan->unaccountedBytes())

<flux:card {{ $attributes->class('space-y-3') }} data-volume-bar>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <flux:heading size="sm">{{ $scan->root }} @if($scan->filesystem)<span class="font-normal text-zinc-500 dark:text-zinc-400">{{ $scan->filesystem }}</span>@endif</flux:heading>
        <flux:text size="sm" class="tabular-nums">{{ Number::fileSize($scan->volumeFree, precision: 1) }} free of {{ Number::fileSize($scan->volumeTotal, precision: 1) }}</flux:text>
    </div>

    <div class="flex h-3 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
        <div class="h-full bg-blue-500" style="width: {{ number_format($scan->percentOfVolume($scan->allocated), 2) }}%" title="Scanned"></div>
        <div class="h-full bg-amber-400" style="width: {{ number_format($scan->percentOfVolume($unaccounted), 2) }}%" title="Unaccounted"></div>
    </div>

    <div class="flex flex-wrap gap-x-5 gap-y-1">
        <flux:text size="xs" class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-blue-500"></span> Scanned {{ Number::fileSize($scan->allocated, precision: 1) }}</flux:text>
        <flux:text size="xs" class="flex items-center gap-1.5" data-unaccounted><span class="size-2 rounded-full bg-amber-400"></span> Unaccounted {{ Number::fileSize($unaccounted ?? 0, precision: 1) }}</flux:text>
        <flux:text size="xs" class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-zinc-300 dark:bg-zinc-600"></span> Free {{ Number::fileSize($scan->volumeFree, precision: 1) }}</flux:text>
    </div>
</flux:card>
