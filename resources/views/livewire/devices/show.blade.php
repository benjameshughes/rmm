<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">{{ $device->hostname }}</flux:heading>
        <div class="flex items-center gap-2">
            <x-device.agent-update-badge :device="$device" :latest-version="$latestAgentVersion" />
            <flux:badge :color="$device->statusColor()" size="lg">{{ $device->statusLabel() }}</flux:badge>
        </div>
    </div>
    <flux:separator variant="subtle" />

    {{-- Stat Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <flux:card class="space-y-1">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">CPU Usage</flux:text>
            <flux:heading size="xl">
                {{ optional($device->latestMetric)->cpu !== null ? number_format($device->latestMetric->cpu, 1).'%' : '—' }}
            </flux:heading>
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">RAM Usage</flux:text>
            <flux:heading size="xl">
                {{ optional($device->latestMetric)->ram !== null ? number_format($device->latestMetric->ram, 1).'%' : '—' }}
            </flux:heading>
            @if(optional($device->latestMetric)->memory_total_mib)
                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                    {{ number_format($device->latestMetric->memory_used_mib / 1024, 1) }} / {{ number_format($device->latestMetric->memory_total_mib / 1024, 1) }} GB
                </flux:text>
            @endif
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Load Average</flux:text>
            @if(optional($device->latestMetric)->load1 !== null)
                <flux:heading size="xl">{{ number_format($device->latestMetric->load1, 2) }}</flux:heading>
                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">
                    {{ number_format($device->latestMetric->load5 ?? 0, 2) }} / {{ number_format($device->latestMetric->load15 ?? 0, 2) }}
                </flux:text>
            @else
                <flux:heading size="xl">—</flux:heading>
            @endif
        </flux:card>

        <flux:card class="space-y-1">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Uptime</flux:text>
            <flux:heading size="xl">{{ $device->latestMetric?->uptimeForHumans() ?? '—' }}</flux:heading>
        </flux:card>
    </div>

    {{-- Alerts Summary --}}
    @if(optional($device->latestMetric)->alerts_warning !== null || optional($device->latestMetric)->alerts_critical !== null)
        <flux:card>
            <div class="flex items-center justify-between">
                <flux:heading size="sm">Netdata Alerts</flux:heading>
                <div class="flex gap-3">
                    <div class="flex items-center gap-2">
                        <div class="h-2.5 w-2.5 rounded-full bg-green-500"></div>
                        <flux:text size="sm">{{ $device->latestMetric->alerts_normal ?? 0 }} Normal</flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-2.5 w-2.5 rounded-full bg-amber-500"></div>
                        <flux:text size="sm">{{ $device->latestMetric->alerts_warning ?? 0 }} Warning</flux:text>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="h-2.5 w-2.5 rounded-full bg-red-500"></div>
                        <flux:text size="sm">{{ $device->latestMetric->alerts_critical ?? 0 }} Critical</flux:text>
                    </div>
                </div>
            </div>
        </flux:card>
    @endif

    {{-- Command Execution --}}
    <flux:card>
        <flux:heading size="sm" class="mb-4">Quick Actions</flux:heading>
        <div class="flex flex-wrap gap-3">
            <flux:button wire:click="powerOff" wire:confirm="Are you sure you want to power off {{ $device->hostname }}?" icon="power" variant="danger">
                Power Off
            </flux:button>
            <flux:button wire:click="restart" wire:confirm="Are you sure you want to restart {{ $device->hostname }}?" icon="arrow-path">
                Restart
            </flux:button>
            <flux:button wire:click="logOff" icon="arrow-right-start-on-rectangle">
                Log Off
            </flux:button>
            <flux:button wire:click="checkForUpdates" icon="arrow-down-tray">
                Check for Updates
            </flux:button>
            <flux:button wire:click="updateAgent" icon="arrow-up-circle">
                Update Agent
            </flux:button>
            <flux:button wire:click="$set('showScriptModal', true)" icon="code-bracket">
                Run Script
            </flux:button>
        </div>
    </flux:card>

    <flux:modal wire:model="showScriptModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Run Script</flux:heading>
                <flux:text class="mt-2">Execute a script on {{ $device->hostname }}.</flux:text>
            </div>

            <flux:select wire:model="selectedScriptId" label="Script" placeholder="Select a script..." variant="listbox" searchable>
                @foreach($scripts as $script)
                    <flux:select.option value="{{ $script->id }}">{{ $script->name }} ({{ $script->platform->name }})</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showScriptModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="runScript" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Recent Commands --}}
    @if($recentCommands->count() > 0)
        <flux:card>
            <flux:heading size="sm" class="mb-4">Recent Commands</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Queued</flux:table.column>
                    <flux:table.column>Type</flux:table.column>
                    <flux:table.column>Command</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Queued By</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($recentCommands as $command)
                        <flux:table.row :key="'command-'.$command->id" class="cursor-pointer" wire:click="$dispatch('show-command', { commandId: {{ $command->id }} })">
                            <flux:table.cell>{{ $command->queued_at->diffForHumans() }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $command->script_type }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text class="text-sm">{{ $command->displayName() }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
<flux:badge size="sm" :color="$command->status->color()">{{ $command->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>{{ $command->queuedBy?->name ?? '—' }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif

    <livewire:commands.detail />

    {{-- Group & Tags --}}
    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="sm" class="mb-4">Group</flux:heading>
            <flux:select wire:model.live="selectedGroupId" placeholder="No group">
                <flux:select.option value="">No group</flux:select.option>
                @foreach($allGroups as $group)
                    <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">Tags</flux:heading>
            <flux:select wire:model.live="selectedTagIds" variant="listbox" multiple placeholder="Select tags...">
                @foreach($allTags as $tag)
                    <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>
    </div>

    {{-- Device Info & System Info --}}
    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="sm" class="mb-4">Device Details</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Last Seen</dt>
                    <dd class="font-medium">{{ $device->last_seen?->diffForHumans() ?? '—' }}</dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">IP Address</dt>
                    <dd class="font-medium font-mono">{{ $device->last_ip ?? '—' }}</dd>
                </div>
                <flux:separator variant="subtle" />
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-zinc-500 dark:text-zinc-400">API Key</dt>
                    <dd class="flex items-center gap-2 font-medium">
                        <flux:badge size="sm" :color="$apiKeyState->color()">{{ $apiKeyState->label() }}</flux:badge>
                        @if($apiKeyStateDetail)
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $apiKeyStateDetail }}</flux:text>
                        @endif
                    </dd>
                </div>
                @if($device->status->canResetEnrolment())
                    <flux:separator variant="subtle" />
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400">Enrolment</dt>
                        <dd>
                            <flux:button size="xs" variant="danger" icon="arrow-path" wire:click="resetEnrolment" wire:confirm="Reset enrolment for {{ $device->hostname }}? The current key stops working immediately. Run 'rmm reenroll' on the device, then approve it again.">
                                Reset enrolment
                            </flux:button>
                        </dd>
                    </div>
                @endif
                @if($device->agent_version)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">Agent Version</dt>
                        <dd class="font-medium">{{ $device->agent_version }}</dd>
                    </div>
                @endif
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading size="sm" class="mb-4">System Information</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Operating System</dt>
                    <dd class="font-medium">{{ $device->os_name ?? $device->os ?? '—' }}</dd>
                </div>
                @if($device->os_version)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">OS Version</dt>
                        <dd class="font-medium">{{ $device->os_version }}</dd>
                    </div>
                @endif
                @if($device->cpu_model)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between gap-4">
                        <dt class="text-zinc-500 dark:text-zinc-400 shrink-0">CPU</dt>
                        <dd class="font-medium text-right">{{ $device->cpu_model }}</dd>
                    </div>
                @endif
                @if($device->cpu_cores)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">CPU Cores</dt>
                        <dd class="font-medium">{{ $device->cpu_cores }}</dd>
                    </div>
                @endif
                @if($device->total_ram_gb)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">Total RAM</dt>
                        <dd class="font-medium">{{ number_format($device->total_ram_gb, 1) }} GB</dd>
                    </div>
                @elseif(optional($device->latestMetric)->memory_total_mib)
                    <flux:separator variant="subtle" />
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">Total RAM</dt>
                        <dd class="font-medium">{{ number_format($device->latestMetric->memory_total_mib / 1024, 1) }} GB</dd>
                    </div>
                @endif
            </dl>
        </flux:card>
    </div>

    {{-- Disk Storage --}}
    @if($diskUsage->isNotEmpty())
        <flux:card>
            <flux:heading size="sm" class="mb-4">Disk Storage</flux:heading>
            <div class="space-y-4">
                @foreach($diskUsage as $disk)
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <div>
                                <flux:text class="font-medium">{{ $disk['name'] }}</flux:text>
                                @if($disk['mountPoint'])
                                    <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $disk['mountPoint'] }}</flux:text>
                                @endif
                            </div>
                            @if($disk['totalGb'] !== null && $disk['availableGb'] !== null)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    {{ number_format($disk['availableGb'], 1) }} GB free of {{ number_format($disk['totalGb'], 1) }} GB
                                </flux:text>
                            @endif
                        </div>
                        @if($disk['usedPercent'] !== null)
                            <div class="h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                                <div class="h-full {{ $disk['barColor'] }} rounded-full transition-all" style="width: {{ number_format($disk['usedPercent'], 1) }}%"></div>
                            </div>
                            <flux:text size="xs" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                {{ number_format($disk['usedPercent'], 1) }}% used
                            </flux:text>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    {{-- Recent Metrics Table --}}
    <flux:card>
        <flux:heading size="sm" class="mb-4">Recent Metrics</flux:heading>
        <flux:table :paginate="$metrics">
            <flux:table.columns>
                <flux:table.column>Recorded</flux:table.column>
                <flux:table.column>CPU</flux:table.column>
                <flux:table.column>RAM</flux:table.column>
                <flux:table.column>Load</flux:table.column>
                <flux:table.column>Alerts</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($metrics as $metric)
                    <flux:table.row>
                        <flux:table.cell>{{ $metric->recorded_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>{{ $metric->cpu !== null ? number_format($metric->cpu, 1).'%' : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $metric->ram !== null ? number_format($metric->ram, 1).'%' : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $metric->load1 !== null ? number_format($metric->load1, 2) : '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if($metric->alerts_critical > 0)
                                <flux:badge size="sm" color="red">{{ $metric->alerts_critical }} critical</flux:badge>
                            @elseif($metric->alerts_warning > 0)
                                <flux:badge size="sm" color="amber">{{ $metric->alerts_warning }} warning</flux:badge>
                            @elseif($metric->alerts_normal !== null)
                                <flux:badge size="sm" color="green">OK</flux:badge>
                            @else
                                —
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No metrics recorded yet</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <div>
        <flux:button as="a" variant="ghost" :href="route('devices.index')" wire:navigate>
            &larr; Back to Devices
        </flux:button>
    </div>
</div>
