<div class="space-y-8">
    <header class="flex h-8 items-baseline justify-between gap-4">
        <flux:heading size="lg" level="1">Dashboard</flux:heading>
    </header>

    <flux:skeleton.group animate="shimmer" class="space-y-8">
        <div class="grid grid-cols-2 items-end gap-x-8 gap-y-6 md:grid-cols-3 xl:grid-cols-5">
            <div class="flex items-end gap-4">
                <flux:skeleton class="size-22 shrink-0 rounded-full" />
                <flux:skeleton.line class="w-14" />
            </div>
            @foreach(range(1, 4) as $index)
                <div class="space-y-2" wire:key="hero-skeleton-{{ $index }}">
                    <flux:skeleton.line class="w-20" />
                    <flux:skeleton class="h-8 w-16" />
                    <flux:skeleton class="h-2 w-full" />
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-x-16 gap-y-6 py-4">
            @foreach(range(1, 2) as $group)
                <div class="flex min-w-64 flex-1 flex-col gap-3" wire:key="fleet-skeleton-{{ $group }}">
                    <flux:skeleton.line class="w-24" />
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(1.75rem,1fr))] gap-y-2">
                        @foreach(range(1, 16) as $square)
                            <flux:skeleton class="size-5 justify-self-center rounded-[3px]" wire:key="fleet-skeleton-{{ $group }}-{{ $square }}" />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-x-12 gap-y-8 lg:grid-cols-12">
            <div class="space-y-3 p-4 lg:col-span-7">
                <flux:skeleton.line class="w-32" />
                @foreach(range(1, 5) as $index)
                    <flux:skeleton.line wire:key="attention-skeleton-{{ $index }}" />
                @endforeach
            </div>
            <div class="space-y-8 lg:col-span-5">
                @foreach(range(1, 2) as $index)
                    <div class="space-y-3" wire:key="chart-skeleton-{{ $index }}">
                        <flux:skeleton.line class="w-32" />
                        <flux:skeleton class="h-44 w-full" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 gap-x-12 gap-y-8 lg:grid-cols-3">
            @foreach(range(1, 3) as $index)
                <div class="space-y-3" wire:key="panel-skeleton-{{ $index }}">
                    <flux:skeleton.line class="w-24" />
                    <div class="flex items-center gap-4">
                        <flux:skeleton class="size-30 shrink-0 rounded-full" />
                        <div class="flex-1 space-y-2">
                            <flux:skeleton.line />
                            <flux:skeleton.line />
                            <flux:skeleton.line />
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </flux:skeleton.group>
</div>
