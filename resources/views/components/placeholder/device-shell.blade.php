<div {{ $attributes->class('space-y-6') }}>
    <div class="space-y-4">
        <flux:skeleton.group animate="shimmer" class="space-y-4">
            <flux:skeleton class="h-4 w-40" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex items-center gap-3">
                        <flux:skeleton class="h-8 w-56" />
                        <flux:skeleton class="h-6 w-20 rounded-full" />
                    </div>
                    <flux:skeleton class="h-4 w-80" />
                </div>

                <div class="flex items-center gap-2">
                    <flux:skeleton class="h-10 w-36" />
                    <flux:skeleton class="h-10 w-28" />
                    <flux:skeleton class="h-10 w-24" />
                </div>
            </div>
        </flux:skeleton.group>
    </div>

    <div class="border-b border-zinc-200 pb-3 dark:border-zinc-700">
        <flux:skeleton.group animate="shimmer" class="flex gap-6">
            @foreach(App\Enums\DeviceTab::cases() as $tab)
                <flux:skeleton class="h-5 w-20" wire:key="tab-skeleton-{{ $tab->value }}" />
            @endforeach
        </flux:skeleton.group>
    </div>

    {{ $slot }}
</div>
