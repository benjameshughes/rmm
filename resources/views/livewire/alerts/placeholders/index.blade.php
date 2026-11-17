<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Alerts</flux:heading>
        <flux:button as="a" :href="route('alert-rules.index')" wire:navigate variant="ghost">
            Manage Rules
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="flex flex-wrap items-center gap-4">
        <flux:skeleton class="h-10 w-48" />
        <flux:skeleton class="h-10 w-48" />
    </flux:skeleton.group>

    <x-placeholder.table :columns="7" :rows="10" />
</div>
