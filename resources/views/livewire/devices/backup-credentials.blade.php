<x-dashboard.section title="Credentials" description="This PC's rest-server user and its restic repository password, as set up on scarif. Stored encrypted and only handed to the agent when it runs a backup script; never shown again.">
    @can('manageBackups', $device)
        <form wire:submit="save" class="space-y-4" data-backup-credentials>
            <div class="grid gap-4 md:grid-cols-3">
                <flux:input wire:model="restUsername" label="Username" description="Normally the lowercase hostname." autocomplete="off" />

                <flux:field>
                    <flux:label badge="{{ $hasRestPassword ? 'Set' : 'Not set' }}">Rest-server password</flux:label>
                    <flux:input type="password" wire:model="restPassword" autocomplete="new-password" :placeholder="$hasRestPassword ? 'Leave blank to keep' : ''" />
                    <flux:error name="restPassword" />
                </flux:field>

                <flux:field>
                    <flux:label badge="{{ $hasRepositoryPassword ? 'Set' : 'Not set' }}">Repository password</flux:label>
                    <flux:input type="password" wire:model="repositoryPassword" autocomplete="new-password" :placeholder="$hasRepositoryPassword ? 'Leave blank to keep' : ''" />
                    <flux:error name="repositoryPassword" />
                </flux:field>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="key">Save credentials</flux:button>
            </div>
        </form>
    @else
        <flux:text>This PC is monitor only, so it cannot run backups.</flux:text>
    @endcan
</x-dashboard.section>
