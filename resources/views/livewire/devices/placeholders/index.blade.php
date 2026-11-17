<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Devices</flux:heading>
        <flux:button as="a" :href="route('devices.pending')" wire:navigate>Pending Approvals</flux:button>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            @foreach(range(1, 6) as $index)
                <flux:skeleton class="h-9 w-28 rounded-lg" wire:key="summary-skeleton-{{ $index }}" />
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <flux:skeleton class="h-10 w-full max-w-sm" />
            <flux:skeleton class="h-10 w-48" />
            <flux:skeleton class="h-10 w-48" />
        </div>
    </flux:skeleton.group>

    <x-placeholder.table :columns="6" :rows="12" />
</div>
