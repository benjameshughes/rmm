<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Backups</flux:heading>
        <flux:text class="mt-1">Every PC's profile backups and every server's backup jobs, worst first.</flux:text>
    </div>
    <flux:separator variant="subtle" />

    <div class="grid gap-4 sm:grid-cols-2 lg:max-w-xl">
        <x-device.list.summary-card label="All backups" :value="$total" icon="cloud-arrow-up" :active="$filter === ''" wire:click="$set('filter', '')" data-backups-filter="all" />
        <x-device.list.summary-card label="Need attention" :value="$attentionCount" icon="exclamation-triangle" :tone="$attentionCount > 0 ? 'text-red-500' : 'text-zinc-400 dark:text-zinc-500'" :active="$filter === 'attention'" wire:click="$set('filter', 'attention')" data-backups-filter="attention" />
    </div>

    <flux:card class="p-0! sm:p-0!">
        @if($rows->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-backups-empty>
                @if($total === 0)
                    <flux:icon name="cloud-arrow-up" class="size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading size="lg">No backups yet</flux:heading>
                    <flux:text class="max-w-md">Enable backups on a PC's Backups tab. Servers show up once their backup scripts write a status file and the agent reports it.</flux:text>
                @else
                    <flux:icon name="shield-check" class="size-10 text-green-500/60" />
                    <flux:heading size="lg">All clear</flux:heading>
                    <flux:text class="max-w-md">Every backup is healthy.</flux:text>
                @endif
            </div>
        @else
            <flux:table class="px-4">
                <flux:table.columns>
                    <flux:table.column>Device</flux:table.column>
                    <flux:table.column>Backup</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column class="hidden sm:table-cell">Last backup</flux:table.column>
                    <flux:table.column class="hidden md:table-cell" align="end">Size</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($rows as $row)
                        <x-backups.overview-row :row="$row" wire:key="backup-row-{{ $row->key }}" />
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
