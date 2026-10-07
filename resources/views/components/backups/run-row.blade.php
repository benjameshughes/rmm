@props(['backup'])

<flux:table.row {{ $attributes }} data-backup-run="{{ $backup->status->value }}">
    <flux:table.cell>
        <span class="text-sm text-zinc-800 dark:text-white" title="{{ $backup->finished_at->format('j M Y, H:i:s') }}">{{ $backup->finished_at->diffForHumans() }}</span>
    </flux:table.cell>
    <flux:table.cell>
        <div class="flex flex-col items-start gap-1">
            <flux:badge size="sm" :color="$backup->status->color()">{{ $backup->status->label() }}</flux:badge>
            @foreach($backup->errors ?? [] as $error)
                <flux:text size="xs" class="max-w-md truncate font-mono" title="{{ $error }}">{{ $error }}</flux:text>
            @endforeach
        </div>
    </flux:table.cell>
    <flux:table.cell class="hidden md:table-cell">
        <span class="font-mono text-sm text-zinc-600 dark:text-zinc-300">{{ $backup->shortSnapshotId() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $backup->filesForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $backup->dataAddedForHumans() ?? '—' }}</span>
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-300">{{ $backup->durationForHumans() ?? '—' }}</span>
    </flux:table.cell>
</flux:table.row>
