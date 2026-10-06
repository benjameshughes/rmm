<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Apps">
    @if($hasSoftwareInventory)
        <x-dashboard.section title="Installed software" :description="$lastChecked">
            @if($device->software_inventoried_at === null)
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-software-empty>
                    <flux:icon name="squares-plus" class="size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading>No inventory yet</flux:heading>
                    <flux:text class="max-w-md">The software inventory runs winget on the device and lists every installed app with its version and any update waiting.</flux:text>
                    @can('runCommands', $device)
                        <x-device.commands.run-button :command="$inventoryCommand" action="refreshInventory" variant="primary">Run now</x-device.commands.run-button>
                    @endcan
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:input wire:model.live.debounce.300ms="softwareSearch" placeholder="Search apps..." icon="magnifying-glass" class="max-w-sm" />
                    @can('runCommands', $device)
                        <div class="flex flex-wrap items-center gap-4">
                            <flux:checkbox wire:model="closeAppFirst" label="Close the app first" description="For uninstalls and upgrades that hang while the app is open" data-close-app-first />
                            <x-device.commands.run-button :command="$inventoryCommand" action="refreshInventory" icon="arrow-path" data-refresh-inventory>Refresh inventory</x-device.commands.run-button>
                        </div>
                    @endcan
                </div>

                <flux:error name="script" />
                <flux:error name="packageId.PackageId" />

                <flux:table :paginate="$software">
                    <flux:table.columns>
                        <flux:table.column>App</flux:table.column>
                        <flux:table.column>Version</flux:table.column>
                        <flux:table.column class="hidden md:table-cell">Source</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse($software as $package)
                            <flux:table.row :key="'package-'.$package->id">
                                <flux:table.cell>
                                    <div class="min-w-0">
                                        <flux:text class="truncate font-medium text-zinc-800 dark:text-white">{{ $package->name }}</flux:text>
                                        <flux:text size="xs" class="truncate font-mono">{{ $package->package_id }}</flux:text>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex flex-col items-start gap-1">
                                        <flux:text size="sm" class="font-mono">{{ $package->installed_version ?? '—' }}</flux:text>
                                        <x-software.update-badge :package="$package" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="hidden md:table-cell">
                                    <flux:text size="sm">{{ $package->source ?? 'Not from winget' }}</flux:text>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @can('runCommands', $device)
                                        <div class="flex justify-end gap-1">
                                            @if($packageCommands->has($package->package_id))
                                                <x-device.commands.busy :command="$packageCommands->get($package->package_id)" />
                                            @else
                                            @if($package->isUpgradable)
                                                <flux:button size="sm" icon="arrow-up-circle" wire:click="upgradePackage({{ $package->id }})">Upgrade</flux:button>
                                            @endif
                                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$dispatch('confirm-action', { heading: 'Uninstall app', message: {{ Js::from('Uninstall '.$package->name.' from '.$device->hostname.'?') }}, confirm: 'Uninstall', danger: true, action: () => $wire.uninstallPackage({{ $package->id }}) })">Uninstall</flux:button>
                                            @endif
                                        </div>
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4">
                                    <div class="py-6 text-center text-zinc-500 dark:text-zinc-400">No apps match "{{ $softwareSearch }}".</div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            @endif
        </x-dashboard.section>
    @endif

    @if(filled($apps))
        <x-device.top-apps :apps="$apps" />
    @else
        <flux:card>
            <flux:text>This device has not reported app usage yet. Windows agents 0.6.0 and Linux agents 0.7.1 and newer send the busiest apps with each report.</flux:text>
        </flux:card>
    @endif
</x-device.shell>
