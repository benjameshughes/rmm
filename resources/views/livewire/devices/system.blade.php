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

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-system-status>
            <x-inventory.status-tile :check="$systemErrorsStatus" href="#system-errors" :detail="$systemErrorsPeriod" />
            <x-inventory.status-tile :check="$hardware->deviceProblemsCheck()" href="#device-problems" />
            <x-inventory.status-tile :check="$security->postureCheck()" href="#security" />
            <x-inventory.status-tile :check="$software->restartCheck()" href="#updates" :detail="$software->pendingRebootReasonsForHumans()" />
        </div>

        <x-inventory.system-errors :events="$systemErrors" :status="$systemErrorsStatus" :window="$software->systemErrorsWindow()" />

        <div class="grid gap-6 lg:grid-cols-2">
            <x-inventory.device-problems :hardware="$hardware" />
            <x-inventory.security :security="$security" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card>
                <flux:heading size="sm" class="mb-4">This PC</flux:heading>
                <x-inventory.facts :facts="$inventory->overview()" />
            </flux:card>
            <div id="updates" class="scroll-mt-6 space-y-6">
                <x-inventory.updates :software="$software" />
                <x-inventory.software :software="$software" />
            </div>
        </div>

        <x-inventory.users :users="$users" />
        <x-inventory.hardware :hardware="$hardware" />
        <x-inventory.third-party :software="$software" />
    @endif
</x-device.shell>
