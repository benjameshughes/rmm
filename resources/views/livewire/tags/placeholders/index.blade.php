<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Tags</flux:heading>
        <flux:skeleton.group animate="shimmer" class="flex items-center gap-2">
            <flux:skeleton class="h-10 w-32" />
            <flux:skeleton class="h-10 w-24" />
        </flux:skeleton.group>
    </div>
    <flux:separator variant="subtle" />

    <flux:card>
        <flux:skeleton.group animate="shimmer" class="flex flex-wrap gap-3">
            @foreach (range(1, 8) as $index)
                <flux:skeleton wire:key="tag-skeleton-{{ $index }}" class="h-11 w-40 rounded-lg" />
            @endforeach
        </flux:skeleton.group>
    </flux:card>
</div>
