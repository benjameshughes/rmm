<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Backups">
    @if(! $this->isWindows)
        <livewire:devices.server-backups :device="$device" :key="'server-backups-'.$device->id" />
    @else
        @island(name: 'backups', always: true)
            <flux:card class="space-y-4" data-backup-state="{{ $this->state->value }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="lg">File backups</flux:heading>
                            <flux:badge size="sm" :color="$this->state->color()" :icon="$this->state->icon()">{{ $this->state->label() }}</flux:badge>
                        </div>
                        <flux:text size="sm">
                            @if($device->last_good_backup_at)
                                Last good backup {{ $device->lastGoodBackupForHumans() }} &middot; {{ $device->last_good_backup_at->inDisplayTimezone()->format('j M Y, H:i') }}
                            @elseif($device->hasBackupCredentials)
                                No good backup yet. Enabled {{ $device->backup_configured_at->diffForHumans() }}.
                            @else
                                Enable backups below to generate this PC's credentials.
                            @endif
                        </flux:text>
                    </div>

                    @can('runCommands', $device)
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button wire:click="$dispatch('migrate-user')" icon="arrows-right-left" variant="ghost" data-migrate-user>Migrate user</flux:button>
                            @if($device->canBackUp())
                                <x-device.commands.run-button :command="$this->inFlight->get(App\Enums\BackupScript::ListSnapshots->value)" action="refreshSnapshots" icon="arrow-path" variant="ghost" data-refresh-snapshots>Refresh snapshots</x-device.commands.run-button>
                                <x-device.commands.run-button :command="$this->inFlight->get(App\Enums\BackupScript::BackUp->value)" action="backUpNow" icon="cloud-arrow-up" variant="primary" data-back-up-now>Back up now</x-device.commands.run-button>
                            @endif
                        </div>
                    @endcan
                </div>

                @if($this->isBackingUp)
                    <flux:callout color="blue" icon="cloud-arrow-up" data-backing-up>
                        <flux:callout.heading>Backing up now…</flux:callout.heading>
                        <flux:callout.text>
                            <x-device.commands.progress :progress="$this->backupProgress" :with-message="false" />
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="script" />
                <flux:error name="backup" />

                @if($this->problem)
                    <flux:callout :variant="$this->state === App\Enums\BackupState::Failed ? 'danger' : 'warning'" icon="exclamation-triangle" data-backup-problem>
                        <flux:callout.text>{{ Str::ucfirst($this->problem) }}.</flux:callout.text>
                    </flux:callout>
                @endif
            </flux:card>

            <x-dashboard.section title="Recent runs" :description="'The last '.config('backup.history_shown').' backups this PC ran.'">
                @if($this->backups->isEmpty())
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
                            @foreach($this->backups as $backup)
                                <x-backups.run-row :backup="$backup" wire:key="backup-{{ $backup->id }}" />
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Snapshots" :description="$device->backup_snapshots_listed_at ? 'On the backup server, listed '.$device->backup_snapshots_listed_at->diffForHumans().', plus any backed up since.' : 'Refresh snapshots to list what is on the backup server.'">
                @if($this->snapshots->isEmpty())
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
                            @foreach($this->snapshots as $snapshot)
                                <x-backups.snapshot-row :snapshot="$snapshot" :device="$device" wire:key="snapshot-{{ $snapshot->id }}" />
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </x-dashboard.section>
        @endisland

        <livewire:devices.backup-credentials :device="$device" :key="'backup-credentials-'.$device->id" />

        <livewire:devices.restore-backup :device="$device" :key="'restore-backup-'.$device->id" />

        <livewire:devices.migrate-user :device="$device" :key="'migrate-user-'.$device->id" />
    @endif
</x-device.shell>
