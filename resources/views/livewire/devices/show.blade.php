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
                <div><span class="text-muted-foreground">OS:</span> {{ $device->os ?? '—' }}</div>
                <div><span class="text-muted-foreground">API Key:</span> {{ $device->api_key ? substr($device->api_key, 0, 8).'…' : '—' }}</div>
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="md">Latest Snapshot</flux:heading>
            <div class="rounded border p-4 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <div class="text-muted-foreground">CPU</div>
                    <div class="text-lg">{{ optional($device->latestMetric)->cpu ? number_format($device->latestMetric->cpu, 2).'%' : '—' }}</div>
                </div>
                <div>
                    <div class="text-muted-foreground">RAM</div>
                    <div class="text-lg">{{ optional($device->latestMetric)->ram ? number_format($device->latestMetric->ram, 2).'%' : '—' }}</div>
                </div>
            </div>
        </div>
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

