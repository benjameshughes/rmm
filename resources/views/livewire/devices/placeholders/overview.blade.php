<x-placeholder.device-shell>
    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach(range(1, 4) as $index)
                <flux:card wire:key="stat-skeleton-{{ $index }}" class="space-y-3">
                    <flux:skeleton.line class="w-1/3" />
                    <flux:skeleton class="h-7 w-1/2" />
                </flux:card>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach(range(1, 2) as $index)
                <flux:card wire:key="chart-skeleton-{{ $index }}" class="space-y-4">
                    <flux:skeleton.line class="w-1/3" />
                    <flux:skeleton class="h-48 w-full" />
                </flux:card>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card class="space-y-3">
                <flux:skeleton.line size="lg" class="w-1/4" />
                <flux:skeleton.line />
                <flux:skeleton.line />
                <flux:skeleton.line class="w-2/3" />
            </flux:card>
            <flux:card class="space-y-3">
                <flux:skeleton.line size="lg" class="w-1/4" />
                <flux:skeleton.line />
                <flux:skeleton.line class="w-3/4" />
            </flux:card>
        </div>
    </flux:skeleton.group>
</x-placeholder.device-shell>
