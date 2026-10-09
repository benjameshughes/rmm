@props(['card', 'device'])

@php($job = $card->job)

<flux:card {{ $attributes->class('space-y-6') }} data-server-backup-job="{{ $job->job }}" data-server-backup-health="{{ $card->health->value }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg">{{ $job->job }}</flux:heading>
                <flux:badge size="sm" :color="$card->health->color()" :icon="$card->health->icon()">{{ $card->health->label() }}</flux:badge>
                @if($job->tool)
                    <flux:badge size="sm" color="zinc">{{ $job->tool }}</flux:badge>
                @endif
            </div>
            @if($job->repository)
                <flux:text size="sm" class="truncate font-mono" title="{{ $job->repository }}">{{ $job->repository }}</flux:text>
            @endif
        </div>

        @if($card->health === App\Enums\ServerBackupHealth::Missing)
            @can('forgetServerBackupJob', $device)
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Job actions" />
                    <flux:menu>
                        <flux:menu.item icon="trash" variant="danger" data-forget-server-backup-job x-on:click="$dispatch('confirm-action', { heading: 'Forget backup job', message: {{ Js::from('Forget '.$job->job.' and its stored snapshots? Nothing on '.$device->hostname.' changes, and the job comes back if its status file is reported again.') }}, confirm: 'Forget', danger: true, action: () => $wire.forget({{ $job->id }}) })">Forget job</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endcan
        @endif
    </div>

    @if($card->problem)
        <flux:callout :variant="$card->health->calloutVariant()" :icon="$card->health->icon()" data-server-backup-problem>
            <flux:callout.text>{{ Str::ucfirst($card->problem) }}.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-device.stats.stat label="Last snapshot" :value="$job->latest_snapshot_at?->diffForHumans() ?? 'None yet'" :detail="$job->lastRunForHumans()" />
        <x-device.stats.stat label="Size backed up" :value="$job->latestSizeForHumans()" detail="Read by the latest snapshot" />
        <x-device.stats.stat label="Snapshots" :value="$job->snapshotCountForHumans()" detail="In the repository" />
        <x-device.stats.stat label="Repository" :value="$job->repositorySizeForHumans()" :detail="$job->compressionForHumans()" />
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <x-device.metrics.chart :chart="$card->sizeChart" :time-format="['month' => 'short', 'day' => 'numeric']" compact />
        <x-device.metrics.chart :chart="$card->addedChart" :time-format="['month' => 'short', 'day' => 'numeric']" compact />
    </div>

    <div class="space-y-3">
        <div>
            <flux:heading size="sm">Snapshot history</flux:heading>
            <flux:text size="sm">{{ $card->snapshotsDescription() }}</flux:text>
        </div>

        @if($card->snapshots->isEmpty())
            <flux:text size="sm" data-server-snapshots-empty>No snapshots reported yet.</flux:text>
        @else
            <flux:table :paginate="$card->snapshots">
                <flux:table.columns>
                    <flux:table.column>Taken</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">ID</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Took</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">Files</flux:table.column>
                    <flux:table.column>Size</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Added</flux:table.column>
                    <flux:table.column class="hidden xl:table-cell">Contents</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($card->snapshots as $snapshot)
                        <x-backups.server-snapshot-row :snapshot="$snapshot" wire:key="server-snapshot-{{ $snapshot->id }}" />
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</flux:card>
