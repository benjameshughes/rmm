<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Hardware</flux:heading>
        <flux:text class="mt-1">Each device's specs from its latest system inventory.</flux:text>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer">
        <flux:skeleton class="h-10 w-full max-w-sm" />
    </flux:skeleton.group>

    <x-placeholder.table :columns="9" :rows="12" />
</div>
