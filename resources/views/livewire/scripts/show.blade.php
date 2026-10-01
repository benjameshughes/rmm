<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $script->name }}</flux:heading>
            @if($script->description)
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $script->description }}</flux:text>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if($script->is_system)
                <flux:badge color="blue" size="lg">System</flux:badge>
            @endif
            <flux:button wire:click="$set('showExecuteModal', true)" variant="primary" icon="play">
                Execute
            </flux:button>
            @unless($script->is_system)
                <flux:button as="a" :href="route('scripts.edit', $script)" wire:navigate icon="pencil-square">
                    Edit
                </flux:button>
            @endunless
        </div>
    </div>
    <flux:separator variant="subtle" />

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="sm" class="mb-4">Details</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Category</dt>
                    <dd><flux:badge size="sm" color="zinc">{{ $script->category->name }}</flux:badge></dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Platform</dt>
                    <dd><flux:badge size="sm" color="zinc">{{ $script->platform->name }}</flux:badge></dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Script Type</dt>
                    <dd><flux:badge size="sm" color="zinc">{{ $script->script_type->name }}</flux:badge></dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Timeout</dt>
                    <dd class="font-medium">{{ $script->timeout_seconds }}s</dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Requires Admin</dt>
                    <dd>
                        @if($script->requires_admin)
                            <flux:badge size="sm" color="amber">Yes</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">No</flux:badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">Script Content</flux:heading>
            <pre class="p-4 bg-zinc-100 dark:bg-zinc-800 rounded-lg text-sm font-mono overflow-x-auto whitespace-pre-wrap">{{ $script->script_content }}</pre>
        </flux:card>
    </div>

    @if($recentCommands->count() > 0)
        <flux:card>
            <flux:heading size="sm" class="mb-4">Recent Executions</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Device</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Queued</flux:table.column>
                    <flux:table.column>Exit Code</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($recentCommands as $command)
                        <flux:table.row :key="'command-'.$command->id" class="cursor-pointer" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })">
                            <flux:table.cell>
                                <flux:button as="a" size="sm" variant="ghost" :href="route('devices.show', $command->device)" wire:navigate>
                                    {{ $command->device->hostname }}
                                </flux:button>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$command->status->color()">
                                    {{ $command->status->label() }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $command->queued_at->diffForHumans() }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="font-mono">{{ $command->exit_code ?? '—' }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <livewire:commands.detail />

    <div>
        <flux:button as="a" variant="ghost" :href="route('scripts.index')" wire:navigate>
            &larr; Back to Scripts
        </flux:button>
    </div>

    <flux:modal wire:model="showExecuteModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Execute Script</flux:heading>
                <flux:text class="mt-2">Run "{{ $script->name }}" on a device.</flux:text>
            </div>

            <flux:select wire:model="selectedDeviceId" label="Device" placeholder="Select a device..." variant="listbox" searchable>
                @foreach($availableDevices as $device)
                    <flux:select.option value="{{ $device->id }}">{{ $device->hostname }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showExecuteModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="executeOnDevice" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
