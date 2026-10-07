@props(['job', 'printer', 'queue', 'device', 'command' => null])

<flux:table.row {{ $attributes }}>
    <flux:table.cell>
        <div class="min-w-0 max-w-xs">
            <flux:text class="truncate font-medium text-zinc-800 dark:text-white">{{ $job->document ?? 'Untitled' }}</flux:text>
            <flux:text size="xs" class="font-mono">#{{ $job->id }}</flux:text>
        </div>
    </flux:table.cell>
    <flux:table.cell class="hidden md:table-cell">
        <flux:text size="sm">{{ $job->userName ?? '—' }}</flux:text>
    </flux:table.cell>
    <flux:table.cell>
        <x-printers.flags :flags="$job->statusFlags()" :empty="$job->statusText ?? 'Queued'" />
    </flux:table.cell>
    <flux:table.cell class="hidden lg:table-cell">
        <flux:text size="sm">{{ $job->submittedForHumans() ?? '—' }}</flux:text>
    </flux:table.cell>
    <flux:table.cell>
        <flux:text size="sm" class="tabular-nums">{{ $job->ageForHumans($queue->collectedAt) ?? '—' }}</flux:text>
    </flux:table.cell>
    <flux:table.cell class="hidden sm:table-cell">
        <flux:text size="sm" class="tabular-nums">{{ $job->pagesForHumans() }}</flux:text>
    </flux:table.cell>
    <flux:table.cell>
        @can('runCommands', $device)
            <div class="flex justify-end">
                @if($command)
                    <x-device.commands.busy :command="$command" />
                @else
                    <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="$dispatch('confirm-action', { heading: 'Cancel print job', message: {{ Js::from('Remove '.($job->document ?? 'job '.$job->id).' from '.$printer->name.'?') }}, confirm: 'Cancel job', danger: true, action: () => $wire.cancelJob({{ $printer->id }}, {{ $job->id }}) })" aria-label="Cancel job {{ $job->id }}" data-cancel-job="{{ $job->id }}" />
                @endif
            </div>
        @endcan
    </flux:table.cell>
</flux:table.row>
