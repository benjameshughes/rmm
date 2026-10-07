<x-dashboard.section title="Backup access" description="This PC's restic repository on the backup server. Its password is generated, stored encrypted and only handed to the agent when it runs a backup script; never shown.">
    @can('manageBackups', $device)
        <div class="space-y-4" data-backup-credentials>
            @if($device->hasBackupCredentials)
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:text>Backing up to repository <span class="font-mono" data-repository-name>{{ $device->backup_repository_name }}</span>.</flux:text>
                    <flux:button size="sm" variant="ghost" icon="no-symbol" class="text-red-400! hover:text-red-300!" x-on:click="$dispatch('confirm-action', { heading: 'Disable backups', message: {{ Js::from('Stop backing up '.$device->hostname.'? Its snapshots on scarif stay, and its repository password is kept so enabling it again carries on in the same repository.') }}, confirm: 'Disable', danger: true, action: () => $wire.disable() })" data-disable-backups>Disable backups</flux:button>
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:text>Backups are off for this PC. Enabling them generates its repository password; its first backup creates the repository.</flux:text>
                    <flux:button variant="primary" icon="key" x-on:click="$dispatch('confirm-action', { heading: 'Enable backups', message: {{ Js::from('Back up '.$device->hostname.'? Its repository password is generated once and is the only key to its backups.') }}, confirm: 'Enable', action: () => $wire.enable() })" data-enable-backups>Enable backups</flux:button>
                </div>
            @endif

            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Repository passwords are encrypted with the RMM's APP_KEY. Keep APP_KEY in Bitwarden, or these backups cannot be decrypted if the RMM is lost.</flux:text>
        </div>
    @else
        <flux:text>This PC is monitor only, so it cannot run backups.</flux:text>
    @endcan
</x-dashboard.section>
