<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Backups">
    @if(! $isWindows)
        <flux:card>
            <flux:text>File backups are only for Windows PCs.</flux:text>
        </flux:card>
    @else
        <flux:card class="space-y-4" data-backup-state="{{ $state->value }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">File backups</flux:heading>
                        <flux:badge size="sm" :color="$state->color()" :icon="$state->icon()">{{ $state->label() }}</flux:badge>
                    </div>
                    <flux:text size="sm">
                        @if($device->last_good_backup_at)
                            Last good backup {{ $device->lastGoodBackupForHumans() }} &middot; {{ $device->last_good_backup_at->format('j M Y, H:i') }}
                        @elseif($device->hasBackupCredentials)
                            No good backup yet. Credentials set {{ $device->backup_configured_at->diffForHumans() }}.
                        @else
                            Set this PC's credentials below once its rest-server user and repository exist on scarif.
                        @endif
                    </flux:text>
                </div>

                @can('runCommands', $device)
                    @if($device->canBackUp())
                        <div class="flex flex-wrap items-center gap-2">
                            <x-device.commands.run-button :command="$inFlight->get(App\Enums\BackupScript::ListSnapshots->value)" action="refreshSnapshots" icon="arrow-path" variant="ghost" data-refresh-snapshots>Refresh snapshots</x-device.commands.run-button>
                            <x-device.commands.run-button :command="$inFlight->get(App\Enums\BackupScript::BackUp->value)" action="backUpNow" icon="cloud-arrow-up" variant="primary" data-back-up-now>Back up now</x-device.commands.run-button>
                        </div>
                    @endif
                @endcan
            </div>

            <flux:error name="script" />
            <flux:error name="backup" />

            @if($problem)
                <flux:callout :variant="$state === App\Enums\BackupState::Failed ? 'danger' : 'warning'" icon="exclamation-triangle" data-backup-problem>
                    <flux:callout.text>{{ Str::ucfirst($problem) }}.</flux:callout.text>
                </flux:callout>
            @endif
        </flux:card>

        <x-dashboard.section title="Recent runs" :description="'The last '.config('backup.history_shown').' backups this PC ran.'">
            @if($backups->isEmpty())
                <flux:text data-backups-empty>No backup has run yet.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Finished</flux:table.column>
                        <flux:table.column>Result</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">Snapshot</flux:table.column>
                        <flux:table.column class="hidden lg:table-cell">Files</flux:table.column>
                        <flux:table.column class="hidden sm:table-cell">Added</flux:table.column>
                        <flux:table.column class="hidden lg:table-cell">Took</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($backups as $backup)
                            <x-backups.run-row :backup="$backup" wire:key="backup-{{ $backup->id }}" />
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-dashboard.section>

        <x-dashboard.section title="Snapshots" :description="$device->backup_snapshots_listed_at ? 'On the backup server, listed '.$device->backup_snapshots_listed_at->diffForHumans().', plus any backed up since.' : 'Refresh snapshots to list what is on the backup server.'">
            @if($snapshots->isEmpty())
                <flux:text data-snapshots-empty>No snapshots listed yet.</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Taken</flux:table.column>
                        <flux:table.column class="hidden sm:table-cell">ID</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">Profiles</flux:table.column>
                        <flux:table.column class="hidden lg:table-cell">Size</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach($snapshots as $snapshot)
                            <x-backups.snapshot-row :snapshot="$snapshot" :device="$device" wire:key="snapshot-{{ $snapshot->id }}" />
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-dashboard.section>

        <livewire:devices.backup-credentials :device="$device" :key="'backup-credentials-'.$device->id" />

        <livewire:devices.restore-backup :device="$device" :key="'restore-backup-'.$device->id" />
    @endif
</x-device.shell>
