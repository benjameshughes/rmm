<div class="space-y-6">
    <flux:heading size="xl">Pending Enrollments</flux:heading>
    <flux:separator variant="subtle" />

    <div class="overflow-hidden rounded border">
        <table class="w-full text-sm">
            <thead class="bg-muted/40">
            <tr>
                <th class="px-4 py-2 text-left">Hostname</th>
                <th class="px-4 py-2 text-left">IP</th>
                <th class="px-4 py-2 text-left">OS</th>
                <th class="px-4 py-2 text-left">Requested</th>
                <th class="px-4 py-2 text-left">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($devices as $device)
                <tr class="border-t" wire:key="pending-{{ $device->id }}">
                    <td class="px-4 py-2 font-medium">{{ $device->hostname }}</td>
                    <td class="px-4 py-2">{{ $device->last_ip ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $device->os ?? '—' }}</td>
                    <td class="px-4 py-2">{{ $device->created_at->diffForHumans() }}</td>
                    <td class="px-4 py-2">
                        <div class="flex gap-2">
                            <flux:button size="xs" variant="primary" wire:click="approve({{ $device->id }})" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="approve({{ $device->id }})">Approve</span>
                                <span wire:loading wire:target="approve({{ $device->id }})">...</span>
                            </flux:button>
                            <flux:button size="xs" variant="danger" wire:click="reject({{ $device->id }})" wire:loading.attr="disabled">Reject</flux:button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-8 text-center text-muted-foreground" colspan="5">No pending devices</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div>
        <flux:button as="a" variant="outline" :href="route('devices.index')" wire:navigate>Back to Devices</flux:button>
    </div>
</div>

