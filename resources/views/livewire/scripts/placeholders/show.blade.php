<div class="space-y-6">
    <flux:skeleton.group animate="shimmer" class="flex items-center justify-between">
        <div class="w-1/2 space-y-2">
            <flux:skeleton.line size="lg" class="w-2/3" />
            <flux:skeleton.line class="w-full" />
        </div>
        <div class="flex items-center gap-2">
            <flux:skeleton class="h-10 w-20" />
            <flux:skeleton class="h-10 w-20" />
        </div>
    </flux:skeleton.group>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="grid gap-6 md:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:skeleton.line class="w-24" />
            @foreach (range(1, 5) as $index)
                <div wire:key="detail-skeleton-{{ $index }}" class="flex justify-between gap-4">
                    <flux:skeleton.line class="w-28" />
                    <flux:skeleton.line class="w-16" />
                </div>
            @endforeach
        </flux:card>

        <flux:card class="space-y-4">
            <flux:skeleton.line class="w-32" />
            <flux:skeleton class="h-48 w-full" />
        </flux:card>
    </flux:skeleton.group>

    <flux:card>
        <flux:skeleton.group animate="shimmer" class="space-y-4">
            <flux:skeleton.line class="w-40" />
            @foreach (range(1, 5) as $index)
                <flux:skeleton.line wire:key="execution-skeleton-{{ $index }}" />
            @endforeach
        </flux:skeleton.group>
    </flux:card>
</div>
