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
            @if($script->is_internal)
                <flux:badge color="zinc" size="lg" data-internal-badge>Internal</flux:badge>
            @else
                <flux:button wire:click="$set('showExecuteModal', true)" variant="primary" icon="play">
                    Run
                </flux:button>
            @endif
            @unless($script->is_system)
                <flux:button as="a" :href="route('scripts.edit', $script)" wire:navigate icon="pencil-square">
                    Edit
                </flux:button>
            @endunless
        </div>
    </div>
    <flux:separator variant="subtle" />

    @if($script->is_internal)
        <flux:callout icon="lock-closed" variant="secondary" heading="Queued by its own feature">
            <flux:callout.text>This script is internal plumbing. Its feature queues it with its own checks and confirmation, so it is never offered in Run Script or schedules.</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="sm" class="mb-4">Details</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Category</dt>
                    <dd><flux:badge size="sm" color="zinc">{{ $script->category->label() }}</flux:badge></dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Platform</dt>
                    <dd><flux:badge size="sm" color="zinc">{{ $script->platform->label() }}</flux:badge></dd>
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

    @island(name: 'executions', always: true)
    @if($this->recentCommands->isNotEmpty())
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
                    @foreach($this->recentCommands as $command)
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
    @endisland


    <div>
        <flux:button as="a" variant="ghost" :href="route('scripts.index')" wire:navigate>
            &larr; Back to Scripts
        </flux:button>
    </div>

    <x-script.run-modal :script="$script" :devices="$availableDevices" :groups="$runGroups" />
</div>
