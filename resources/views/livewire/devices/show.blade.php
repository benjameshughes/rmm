<div class="space-y-6">
    <flux:heading size="xl">{{ $device->hostname }}</flux:heading>
    <flux:separator variant="subtle" />

    <div class="grid gap-6 md:grid-cols-2">
        <div class="space-y-3">
            <flux:heading size="md">Overview</flux:heading>
            <div class="rounded border p-4 space-y-2 text-sm">
                <div><span class="text-muted-foreground">Status:</span>
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
                </div>
                <div><span class="text-muted-foreground">Last seen:</span> {{ $device->last_seen?->diffForHumans() ?? '—' }}</div>
                <div><span class="text-muted-foreground">IP:</span> {{ $device->last_ip ?? '—' }}</div>
                <div><span class="text-muted-foreground">API Key:</span> {{ $device->api_key ? substr($device->api_key, 0, 8).'…' : '—' }}</div>
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="md">Latest Metrics</flux:heading>
            <div class="rounded border p-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <div class="text-muted-foreground">CPU Usage</div>
                    <div class="text-lg">{{ optional($device->latestMetric)->cpu ? number_format($device->latestMetric->cpu, 1).'%' : '—' }}</div>
                </div>
                <div>
                    <div class="text-muted-foreground">RAM Usage</div>
                    <div class="text-lg">{{ optional($device->latestMetric)->ram ? number_format($device->latestMetric->ram, 1).'%' : '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <div class="space-y-3">
            <flux:heading size="md">System Information</flux:heading>
            <div class="rounded border p-4 space-y-2 text-sm">
                <div><span class="text-muted-foreground">Operating System:</span> {{ $device->os_name ?? $device->os ?? '—' }}</div>
                @if($device->os_version)
                    <div><span class="text-muted-foreground">OS Version:</span> {{ $device->os_version }}</div>
                @endif
                @if($device->cpu_model)
                    <div><span class="text-muted-foreground">CPU Model:</span> {{ $device->cpu_model }}</div>
                @endif
                @if($device->cpu_cores)
                    <div><span class="text-muted-foreground">CPU Cores:</span> {{ $device->cpu_cores }}</div>
                @endif
                @if($device->total_ram_gb)
                    <div><span class="text-muted-foreground">Total RAM:</span> {{ number_format($device->total_ram_gb, 1) }} GB</div>
                @endif
            </div>
        </div>

        @if($device->disks && count($device->disks) > 0)
            <div class="space-y-3">
                <flux:heading size="md">Disk Storage</flux:heading>
                <div class="rounded border p-4 space-y-3 text-sm">
                    @foreach($device->disks as $disk)
                        <div class="pb-3 border-b last:border-b-0 last:pb-0">
                            <div class="font-medium">{{ $disk['name'] ?? '—' }}</div>
                            @if(isset($disk['mount_point']))
                                <div class="text-xs text-muted-foreground">{{ $disk['mount_point'] }}</div>
                            @endif
                            @if(isset($disk['total_gb']) && isset($disk['available_gb']))
                                <div class="mt-1">
                                    <div class="text-xs">
                                        {{ number_format($disk['available_gb'], 1) }} GB free of {{ number_format($disk['total_gb'], 1) }} GB
                                        ({{ number_format((($disk['total_gb'] - $disk['available_gb']) / $disk['total_gb']) * 100, 1) }}% used)
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="md">Recent Metrics</flux:heading>
        <div class="overflow-hidden rounded border">
            <table class="w-full text-sm">
                <thead class="bg-muted/40">
                <tr>
                    <th class="px-4 py-2 text-left">Recorded At</th>
                    <th class="px-4 py-2 text-left">CPU</th>
                    <th class="px-4 py-2 text-left">RAM</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($metrics as $metric)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $metric->recorded_at->diffForHumans() }}</td>
                        <td class="px-4 py-2">{{ $metric->cpu !== null ? number_format($metric->cpu, 2).'%' : '—' }}</td>
                        <td class="px-4 py-2">{{ $metric->ram !== null ? number_format($metric->ram, 2).'%' : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-muted-foreground" colspan="3">No metrics yet</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $metrics->links() }}
    </div>

    <div>
        <flux:button as="a" variant="outline" :href="route('devices.index')" wire:navigate>Back to Devices</flux:button>
    </div>
</div>

