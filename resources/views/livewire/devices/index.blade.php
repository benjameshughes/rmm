<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Devices</flux:heading>
        <flux:button as="a" :href="route('devices.pending')" wire:navigate>
            Pending Approvals
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search hostname, IP, OS..." class="max-w-sm" icon="magnifying-glass" />
    </div>

    <flux:card>
        <flux:table :paginate="$devices">
            <flux:table.columns>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>System</flux:table.column>
                <flux:table.column>Current Load</flux:table.column>
                <flux:table.column>Last Seen</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($devices as $device)
                    <flux:table.row>
                        <flux:table.cell>
                            <div>
                                <flux:text class="font-medium">{{ $device->hostname }}</flux:text>
                                <flux:text size="xs" class="text-zinc-500 dark:text-zinc-400 font-mono">{{ $device->last_ip ?? '—' }}</flux:text>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($device->status === \App\Models\Device::STATUS_ACTIVE)
                                @if($device->isOnline())
                                    <flux:badge color="green">Online</flux:badge>
                                @else
                                    <flux:badge color="red">Offline</flux:badge>
                                @endif
                            @elseif($device->status === \App\Models\Device::STATUS_PENDING)
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
                            <flux:button as="a" size="sm" variant="ghost" :href="route('devices.show', $device)" wire:navigate>
                                View
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">No devices found</div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
