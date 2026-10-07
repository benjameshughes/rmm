@props(['snapshot', 'device'])

<flux:table.row {{ $attributes }} data-snapshot="{{ $snapshot->short_id }}">
    <flux:table.cell>
        <span class="text-sm text-zinc-800 dark:text-white" title="{{ $snapshot->taken_at->format('j M Y, H:i:s') }}">{{ $snapshot->taken_at->format('D j M Y, H:i') }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <span class="font-mono text-sm text-zinc-600 dark:text-zinc-300">{{ $snapshot->short_id }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden md:table-cell">
        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $snapshot->profilesForHumans() ?: '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $snapshot->sizeForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell align="end">
        @can('runCommands', $device)
            <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="$dispatch('restore-snapshot', { snapshotId: '{{ $snapshot->snapshot_id }}' })" data-restore-snapshot>Restore</flux:button>
        @endcan
    </flux:table.cell>
</flux:table.row>
