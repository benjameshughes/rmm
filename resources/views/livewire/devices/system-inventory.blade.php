<div class="flex flex-wrap items-center justify-between gap-3" data-system-inventory-control>
    <flux:text size="sm">System inventory · {{ $collected }}</flux:text>
    @can('runCommands', $device)
        <x-device.commands.run-button :command="$inventoryCommand" action="refreshInventory" icon="arrow-path" data-refresh-inventory>Run now</x-device.commands.run-button>
    @endcan
</div>
