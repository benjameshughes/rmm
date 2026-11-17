<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Scripts</flux:heading>
            <flux:skeleton.line animate="shimmer" class="mt-1 w-72" />
        </div>
        <flux:button as="a" :href="route('scripts.create')" wire:navigate variant="primary" icon="plus">New Script</flux:button>
    </div>

    <flux:skeleton.group animate="shimmer" class="space-y-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <flux:skeleton class="h-10 w-full sm:max-w-md" />
            <div class="flex gap-3">
                <flux:skeleton class="h-10 w-48" />
                <flux:skeleton class="h-10 w-44" />
            </div>
        </div>

        @foreach (range(1, 3) as $group)
            <div wire:key="script-group-skeleton-{{ $group }}" class="space-y-3">
                <flux:skeleton.line class="w-32" />
                @foreach (range(1, 3) as $row)
                    <flux:skeleton wire:key="script-row-skeleton-{{ $group }}-{{ $row }}" class="h-16 w-full rounded-lg" />
                @endforeach
            </div>
        @endforeach
    </flux:skeleton.group>
</div>
