<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Audit Log</flux:heading>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="flex flex-wrap items-center gap-4">
        <flux:skeleton class="h-10 w-72" />
        <flux:skeleton class="h-10 w-48" />
        <flux:skeleton class="h-10 w-56" />
    </flux:skeleton.group>

    <x-placeholder.table :columns="6" :rows="12" />
</div>
