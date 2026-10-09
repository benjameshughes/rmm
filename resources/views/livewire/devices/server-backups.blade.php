<div class="space-y-6">
    @if($cards->isEmpty())
        <flux:card class="space-y-2" data-server-backups-empty>
            <div class="flex items-center gap-2">
                <flux:icon name="cloud-arrow-up" class="size-5 text-zinc-400 dark:text-zinc-500" />
                <flux:heading size="lg">Server backups</flux:heading>
            </div>
            <flux:text>No backup status files yet. Add the status snippet to the backup script, and each job shows up here with its next report.</flux:text>
        </flux:card>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg">Server backups</flux:heading>
                    @if($attentionCount > 0)
                        <flux:badge size="sm" color="red" icon="exclamation-triangle" data-server-backups-attention="{{ $attentionCount }}">{{ $attentionCount }} of {{ $cards->count() }} need attention</flux:badge>
                    @else
                        <flux:badge size="sm" color="green" icon="shield-check" data-server-backups-attention="0">All {{ $cards->count() }} healthy</flux:badge>
                    @endif
                </div>
                <flux:text size="sm">From the status files this server's backup scripts write. Watch only: nothing here runs on the server.</flux:text>
            </div>
        </div>

        @foreach($cards as $card)
            <x-backups.server-job :card="$card" :device="$device" wire:key="server-backup-job-{{ $card->job->id }}" />
        @endforeach
    @endif
</div>
