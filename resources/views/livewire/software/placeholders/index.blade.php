<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Software</flux:heading>
            <flux:skeleton.group animate="shimmer" class="mt-2">
                <flux:skeleton.line class="w-96 max-w-full" />
            </flux:skeleton.group>
        </div>
        <flux:skeleton.group animate="shimmer">
            <flux:skeleton class="h-10 w-40" />
        </flux:skeleton.group>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="flex flex-wrap items-center gap-4">
        <flux:skeleton class="h-10 w-full max-w-sm" />
        @foreach(range(1, 4) as $index)
            <flux:skeleton class="h-10 w-48" wire:key="filter-skeleton-{{ $index }}" />
        @endforeach
    </flux:skeleton.group>

    <x-placeholder.table :columns="5" :rows="12" />
</div>
