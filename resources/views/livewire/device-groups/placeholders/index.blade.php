<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Device Groups</flux:heading>
        <div class="flex items-center gap-2">
            <flux:button as="a" :href="route('tags.index')" wire:navigate variant="ghost">
                Manage Tags
            </flux:button>
            <flux:skeleton animate="shimmer" class="h-10 w-28" />
        </div>
    </div>
    <flux:separator variant="subtle" />

    <x-placeholder.headed-table :headings="['Name', 'Description', 'Devices', '']" :rows="6" />
</div>
