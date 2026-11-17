<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Trends</flux:heading>
            <flux:skeleton.group animate="shimmer" class="mt-2">
                <flux:skeleton.line class="w-96 max-w-full" />
            </flux:skeleton.group>
        </div>
        <flux:skeleton.group animate="shimmer" class="flex flex-wrap items-center gap-2">
            <flux:skeleton class="h-8 w-40" />
            <flux:skeleton class="h-8 w-48" />
        </flux:skeleton.group>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="space-y-3">
            <flux:skeleton.line class="w-64" />
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach(range(1, 4) as $index)
                    <flux:skeleton class="h-28 rounded-xl" wire:key="hero-metric-skeleton-{{ $index }}" />
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                @foreach(range(1, 4) as $index)
                    <flux:skeleton class="h-16 rounded-xl" wire:key="detail-metric-skeleton-{{ $index }}" />
                @endforeach
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <flux:skeleton class="h-64 rounded-xl" />
                <flux:skeleton class="h-64 rounded-xl" />
            </div>
            <div class="space-y-3">
                <flux:skeleton.line class="w-40" />
                @foreach(range(1, 6) as $index)
                    <flux:skeleton.line wire:key="change-skeleton-{{ $index }}" />
                @endforeach
            </div>
        </div>
    </flux:skeleton.group>

    <x-placeholder.table :columns="6" :rows="10" />
</div>
