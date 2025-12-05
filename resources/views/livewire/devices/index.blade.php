<div class="space-y-6">
    <flux:heading size="xl">Devices</flux:heading>
    <flux:separator variant="subtle" />

    <div class="flex items-center justify-between gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search hostname, IP, OS..." class="w-full max-w-md" />
        <div class="flex gap-2">
            <flux:button as="a" :href="route('devices.pending')" wire:navigate>Pending</flux:button>
        </div>
    </div>

    <div class="overflow-hidden rounded border">
        <table class="w-full text-sm">
            <thead class="bg-muted/40">
            <tr>
                <th class="px-4 py-2 text-left">Hostname</th>
                <th class="px-4 py-2 text-left">Status</th>
                <th class="px-4 py-2 text-left">Last Seen</th>
                <th class="px-4 py-2 text-left">IP</th>
                <th class="px-4 py-2 text-left">OS</th>
                <th class="px-4 py-2 text-left">CPU</th>
                <th class="px-4 py-2 text-left">RAM</th>
                <th class="px-4 py-2 text-left">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($devices as $device)
                <tr class="border-t">
                    <td class="px-4 py-2 font-medium">{{ $device->hostname }}</td>
                    <td class="px-4 py-2">
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
                    </td>
                    <td class="px-4 py-2">{{ $device->last_seen?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $device->last_ip ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $device->os ?? '—' }}</td>
                    <td class="px-4 py-2">{{ optional($device->latestMetric)->cpu ? number_format($device->latestMetric->cpu, 2).'%' : '—' }}</td>
                    <td class="px-4 py-2">{{ optional($device->latestMetric)->ram ? number_format($device->latestMetric->ram, 2).'%' : '—' }}</td>
                    <td class="px-4 py-2">
                        <flux:button as="a" size="xs" variant="outline" :href="route('devices.show', $device)" wire:navigate>View</flux:button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-muted-foreground" colspan="8">No devices found</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $devices->links() }}
    </div>
</div>

