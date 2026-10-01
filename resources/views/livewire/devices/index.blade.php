<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Devices</flux:heading>
        <flux:button as="a" :href="route('devices.pending')" wire:navigate>
            Pending Approvals
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search hostname, IP, OS..." class="max-w-sm" icon="magnifying-glass" />
        <flux:select wire:model.live="groupFilter" placeholder="All Groups" class="max-w-48">
            <flux:select.option value="">All Groups</flux:select.option>
            @foreach($groups as $group)
                <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="tagFilter" placeholder="All Tags" class="max-w-48">
            <flux:select.option value="">All Tags</flux:select.option>
            @foreach($tags as $tag)
                <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if(count($selectedDevices) > 0)
        <flux:card class="!bg-blue-50 dark:!bg-blue-900/20 !border-blue-200 dark:!border-blue-800">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <flux:text class="font-medium">{{ count($selectedDevices) }} {{ Str::plural('device', count($selectedDevices)) }} selected</flux:text>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" wire:click="bulkRestart" wire:confirm="Restart {{ count($selectedDevices) }} devices?" icon="arrow-path">
                        Restart All
                    </flux:button>
                    <flux:button size="sm" wire:click="bulkPowerOff" wire:confirm="Power off {{ count($selectedDevices) }} devices?" icon="power" variant="danger">
                        Power Off All
                    </flux:button>
                    <flux:button size="sm" wire:click="$set('showBulkScriptModal', true)" icon="code-bracket">
                        Run Script
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="clearSelection">
                        Clear
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    <flux:card>
        <flux:table :paginate="$devices">
            <flux:table.columns>
                <flux:table.column>
                    <flux:checkbox wire:model.live="selectAll" />
                </flux:table.column>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>System</flux:table.column>
                <flux:table.column>Current Load</flux:table.column>
                <flux:table.column>Last Seen</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($devices as $device)
                    <flux:table.row wire:key="device-{{ $device->id }}">
                        <flux:table.cell>
                            <flux:checkbox wire:model.live="selectedDevices" value="{{ $device->id }}" />
                        </flux:table.cell>
                        <flux:table.cell>
                            <div>
                                <flux:text class="font-medium">{{ $device->hostname }}</flux:text>
                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400 font-mono">{{ $device->last_ip ?? '—' }}</flux:text>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @if($device->group)
                                        <flux:badge size="sm" :color="$device->group->color ?? 'zinc'">{{ $device->group->name }}</flux:badge>
                                    @endif
                                    @foreach($device->tags as $tag)
                                        <flux:badge size="sm" :color="$tag->color ?? 'zinc'" variant="outline">{{ $tag->name }}</flux:badge>
                                    @endforeach
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($device->status === \App\Enums\DeviceStatus::Active)
                                @if($device->isOnline)
                                    <flux:badge color="green">Online</flux:badge>
                                @else
                                    <flux:badge color="red">Offline</flux:badge>
                                @endif
                            @elseif($device->status === \App\Enums\DeviceStatus::Pending)
                                <flux:badge color="amber">Pending</flux:badge>
                            @else
                                <flux:badge color="gray">Revoked</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($device->os_name)
                                <div>
                                    <flux:text>{{ $device->os_name }}</flux:text>
                                    @if($device->cpu_cores)
                                        <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">{{ $device->cpu_cores }} cores &bull; {{ $device->total_ram_gb ? number_format($device->total_ram_gb).'GB' : '—' }}</flux:text>
                                    @endif
                                </div>
                            @else
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $device->os ?? '—' }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if(optional($device->latestMetric)->cpu !== null)
                                <div class="flex items-center gap-3">
                                    <div class="text-center">
                                        <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">CPU</flux:text>
                                        <flux:text class="font-medium {{ $device->latestMetric->cpu > 80 ? 'text-red-600 dark:text-red-400' : '' }}">
                                            {{ number_format($device->latestMetric->cpu, 0) }}%
                                        </flux:text>
                                    </div>
                                    <div class="text-center">
                                        <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400">RAM</flux:text>
                                        <flux:text class="font-medium {{ $device->latestMetric->ram > 80 ? 'text-red-600 dark:text-red-400' : '' }}">
                                            {{ number_format($device->latestMetric->ram, 0) }}%
                                        </flux:text>
                                    </div>
                                    @if($device->latestMetric->alerts_critical > 0)
                                        <flux:badge size="sm" color="red">{{ $device->latestMetric->alerts_critical }}</flux:badge>
                                    @elseif($device->latestMetric->alerts_warning > 0)
                                        <flux:badge size="sm" color="amber">{{ $device->latestMetric->alerts_warning }}</flux:badge>
                                    @endif
                                </div>
                            @else
                                <flux:text class="text-zinc-500 dark:text-zinc-400">—</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $device->last_seen?->diffForHumans() ?? '—' }}</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <flux:button as="a" size="sm" variant="ghost" :href="route('devices.show', $device)" wire:navigate>
                                    View
                                </flux:button>
                                <flux:dropdown>
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" square aria-label="Actions" />
                                    <flux:menu>
                                        <flux:menu.item icon="power" wire:click="powerOff({{ $device->id }})" wire:confirm="Are you sure you want to power off {{ $device->hostname }}?">
                                            Power Off
                                        </flux:menu.item>
                                        <flux:menu.item icon="arrow-path" wire:click="restart({{ $device->id }})" wire:confirm="Are you sure you want to restart {{ $device->hostname }}?">
                                            Restart
                                        </flux:menu.item>
                                        <flux:menu.item icon="arrow-down-tray" wire:click="checkForUpdates({{ $device->id }})">
                                            Check for Updates
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="7">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No devices found</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showBulkScriptModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Run Script</flux:heading>
                <flux:text class="mt-2">Execute a script on {{ count($selectedDevices) }} {{ Str::plural('device', count($selectedDevices)) }}.</flux:text>
            </div>

            <flux:select wire:model="bulkScriptId" label="Script" placeholder="Select a script..." variant="listbox" searchable>
                @foreach($scripts as $script)
                    <flux:select.option value="{{ $script->id }}">{{ $script->name }} ({{ $script->platform->name }})</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showBulkScriptModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="bulkRunScript" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
