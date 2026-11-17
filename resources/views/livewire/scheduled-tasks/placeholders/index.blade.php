<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Scheduled Tasks</flux:heading>
        <flux:skeleton animate="shimmer" class="h-10 w-36" />
    </div>
    <flux:separator variant="subtle" />

    <x-placeholder.headed-table :headings="['Name', 'Action', 'Target', 'Schedule', 'Last Run', 'Next Run', 'Active', '']" :rows="6" />
</div>
