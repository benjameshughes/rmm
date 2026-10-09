@props(['snapshot'])

<flux:table.row {{ $attributes }} data-server-snapshot="{{ $snapshot->short_id }}">
    <flux:table.cell>
        <span class="text-sm text-zinc-800 dark:text-white" title="{{ $snapshot->taken_at->inDisplayTimezone()->format('j M Y, H:i:s') }}">{{ $snapshot->taken_at->diffForHumans() }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <span class="font-mono text-sm text-zinc-600 dark:text-zinc-300">{{ $snapshot->short_id }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $snapshot->durationForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $snapshot->filesForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell>
        <span class="text-sm tabular-nums text-zinc-800 dark:text-white">{{ $snapshot->sizeForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden md:table-cell">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $snapshot->dataAddedForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden xl:table-cell">
        <span class="block max-w-xs truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" title="{{ $snapshot->contentsForHumans() }}">{{ $snapshot->contentsForHumans() }}</span>
    </flux:table.cell>
</flux:table.row>
