<x-placeholder.device-shell>
    <flux:skeleton.group animate="shimmer" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:skeleton class="h-8 w-48" />
            <flux:skeleton class="h-10 w-32" />
        </div>
        <flux:skeleton class="h-3 w-full rounded-full" />
    </flux:skeleton.group>

    <x-placeholder.table :columns="4" :rows="10" />
</x-placeholder.device-shell>
