<div class="space-y-6">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('software.index')" wire:navigate>Software</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $packageId }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:card class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-software-removed>
        <flux:icon name="check-circle" class="size-10 text-green-500" />
        <flux:heading size="lg">No device has this app any more</flux:heading>
        <flux:text class="font-mono">{{ $packageId }}</flux:text>
        <flux:button size="sm" :href="route('software.index')" wire:navigate icon="arrow-left">Back to Software</flux:button>
    </flux:card>
</div>
