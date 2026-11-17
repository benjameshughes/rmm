<div class="space-y-6">
    <flux:skeleton.group animate="shimmer" class="space-y-4">
        <flux:skeleton.line class="w-40" />
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <flux:skeleton class="h-8 w-64" />
                <flux:skeleton.line class="w-48" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:skeleton class="h-10 w-48" />
                <flux:skeleton class="h-10 w-44" />
                <flux:skeleton class="h-10 w-48" />
            </div>
        </div>
    </flux:skeleton.group>

    <x-placeholder.table :columns="4" :rows="8" />
</div>
