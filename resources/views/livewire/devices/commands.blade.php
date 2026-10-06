<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Commands">
    <flux:card>
        <flux:table :paginate="$commands">
            <flux:table.columns>
                <flux:table.column>Command</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Queued</flux:table.column>
                <flux:table.column>Queued By</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($commands as $command)
                    <flux:table.row :key="'command-'.$command->id" class="cursor-pointer" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $command->displayName() }}</flux:text>
                                <flux:text size="sm" class="font-mono">{{ $command->script_type }}</flux:text>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            <x-device.commands.status :command="$command" />
                        </flux:table.cell>
                        <flux:table.cell>{{ $command->queued_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $command->queuedBy?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">
                            <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">No commands run on this device yet.</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

</x-device.shell>
