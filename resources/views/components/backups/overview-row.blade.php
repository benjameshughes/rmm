@props(['row'])

<flux:table.row {{ $attributes }} data-backup-row="{{ $row->key }}" data-backup-status="{{ $row->statusValue }}">
    <flux:table.cell>
        <a href="{{ route('devices.backups', $row->device) }}" wire:navigate class="text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $row->device->hostname }}</a>
        <flux:text size="xs">{{ $row->kind }}</flux:text>
    </flux:table.cell>
    <flux:table.cell>
        <span class="text-sm text-zinc-800 dark:text-white">{{ $row->name }}</span>
        @if($row->problem)
            <flux:text size="xs" class="max-w-xs truncate" title="{{ Str::ucfirst($row->problem) }}">{{ Str::ucfirst($row->problem) }}</flux:text>
        @endif
    </flux:table.cell>
    <flux:table.cell>
        <flux:badge size="sm" :color="$row->statusColor" :icon="$row->statusIcon">{{ $row->statusLabel }}</flux:badge>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <span class="text-sm text-zinc-600 dark:text-zinc-300" title="{{ $row->lastBackupAt?->inDisplayTimezone()->format('j M Y, H:i') }}">{{ $row->lastBackupForHumans() }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden md:table-cell" align="end">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $row->size ?? '—' }}</span>
    </flux:table.cell>
</flux:table.row>
