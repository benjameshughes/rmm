<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Alert Rules</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button as="a" :href="route('alerts.index')" wire:navigate variant="ghost">
                View Alerts
            </flux:button>
            <flux:skeleton.group animate="shimmer">
                <flux:skeleton class="h-10 w-28" />
            </flux:skeleton.group>
        </div>
    </div>
    <flux:separator variant="subtle" />

    <x-placeholder.table :columns="7" :rows="6" />
</div>
