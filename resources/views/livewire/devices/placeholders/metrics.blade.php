<x-placeholder.device-shell>
    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:skeleton class="h-6 w-32" />
            <flux:skeleton class="h-8 w-64" />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            @foreach(range(1, 4) as $index)
                <flux:card wire:key="chart-skeleton-{{ $index }}" class="space-y-4">
                    <flux:skeleton.line class="w-1/3" />
                    <flux:skeleton class="h-56 w-full" />
                </flux:card>
            @endforeach
        </div>
    </flux:skeleton.group>
</x-placeholder.device-shell>
