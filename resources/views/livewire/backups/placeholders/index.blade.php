<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Backups</flux:heading>
        <flux:text class="mt-1">Every PC's profile backups and every server's backup jobs, worst first.</flux:text>
    </div>
    <flux:separator variant="subtle" />

    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 lg:max-w-xl">
            <flux:skeleton class="h-24 rounded-xl" />
            <flux:skeleton class="h-24 rounded-xl" />
        </div>

        <flux:card class="space-y-4">
            @foreach (range(1, 8) as $index)
                <div wire:key="backup-skeleton-{{ $index }}" class="flex items-center gap-4">
                    <flux:skeleton.line class="w-1/5" />
                    <flux:skeleton.line class="w-1/4" />
                    <flux:skeleton class="h-5 w-16 rounded-full" />
                    <flux:skeleton.line class="hidden w-1/6 sm:block" />
                    <flux:skeleton.line class="ml-auto hidden w-16 md:block" />
                </div>
            @endforeach
        </flux:card>
    </flux:skeleton.group>
</div>
