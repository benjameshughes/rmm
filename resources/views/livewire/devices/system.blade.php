<x-device.shell :device="$device" :current="App\Enums\DeviceTab::System">
    @if(! $hasSystemInventory)
        <flux:card>
            <flux:text>The system inventory is a PowerShell script, so it is only collected from Windows devices that accept commands.</flux:text>
        </flux:card>
    @elseif($inventory === null)
        <x-dashboard.section title="System inventory">
            <div class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-system-empty>
                <flux:icon name="computer-desktop" class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>No inventory yet</flux:heading>
                <flux:text class="max-w-md">The system inventory reads the hardware, Windows, security settings, users, updates and third-party extras from the device. It changes nothing.</flux:text>
                @can('runCommands', $device)
                    <x-device.commands.run-button :command="$inventoryCommand" action="refreshInventory" variant="primary">Run now</x-device.commands.run-button>
                @endcan
            </div>
        </x-dashboard.section>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:text size="sm">{{ $collected }}</flux:text>
            @can('runCommands', $device)
                <x-device.commands.run-button :command="$inventoryCommand" action="refreshInventory" icon="arrow-path" data-refresh-inventory>Run now</x-device.commands.run-button>
            @endcan
        </div>

        <flux:error name="script" />

        <flux:card>
            <flux:heading size="sm" class="mb-4">Overview</flux:heading>
            <x-inventory.facts :facts="$inventory->overview()" />
        </flux:card>

        <x-inventory.security :security="$security" />
        <x-inventory.users :users="$users" />
        <x-inventory.hardware :hardware="$hardware" />
        <x-inventory.software :software="$software" />
    @endif
</x-device.shell>
