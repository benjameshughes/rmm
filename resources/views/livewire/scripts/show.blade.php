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

    @if($script->parameters->isNotEmpty())
        <flux:card>
            <flux:heading size="sm" class="mb-1">Parameters</flux:heading>
            <flux:text size="sm" class="mb-4 text-zinc-500 dark:text-zinc-400">Values reach the script as environment variables. Agents older than {{ config('agent.parameters_min_version') }} cannot run this script.</flux:text>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Variable</flux:table.column>
                    <flux:table.column>Label</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Required</flux:table.column>
                    <flux:table.column>Default</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($script->parameters as $parameter)
                        <flux:table.row :key="'parameter-'.$parameter->name">
                            <flux:table.cell>
                                <flux:text class="font-mono">RMM_{{ $parameter->name }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>{{ $parameter->label }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $parameter->type->label() }}</flux:badge>
                                @if($parameter->options)
                                    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ implode(', ', $parameter->options) }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($parameter->isRequired)
                                    <flux:badge size="sm" color="amber">Yes</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">No</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="font-mono">{{ $parameter->default ?? '—' }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

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
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="$command->status->color()">
                                        {{ $command->status->label() }}
                                    </flux:badge>
                                    @can('cancel', $command)
                                        <flux:button size="sm" variant="ghost" icon="x-circle" x-on:click.stop="$dispatch('confirm-action', { heading: 'Cancel command', message: 'Cancel this command before it runs?', confirm: 'Cancel command', danger: true, action: () => $wire.cancelCommand({{ $command->id }}) })">Cancel</flux:button>
                                    @endcan
                                </div>
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
                <flux:text class="mt-2">Run "{{ $script->name }}" on one or more devices.</flux:text>
            </div>

            <flux:pillbox wire:model="selectedDeviceIds" multiple searchable label="Devices" placeholder="Select devices...">
                @foreach($availableDevices as $device)
                    <flux:pillbox.option value="{{ $device->id }}">{{ $device->hostname }}</flux:pillbox.option>
                @endforeach
            </flux:pillbox>

            <x-script.parameter-inputs :parameters="$this->parameterFields" />

            <flux:error name="script" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showExecuteModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="executeOnDevices" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
