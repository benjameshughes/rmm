<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Staff</flux:heading>
        <flux:skeleton animate="shimmer" class="h-10 w-32" />
    </div>
    <flux:separator variant="subtle" />

    <x-placeholder.headed-table :headings="['Account', 'Targets', 'Latest sync', 'Last logon', '']" :rows="6" />
</div>
