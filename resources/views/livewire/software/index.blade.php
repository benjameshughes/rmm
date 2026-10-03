<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Software</flux:heading>
        <flux:text class="mt-1">Apps installed across {{ $inventoriedDevices }} {{ Str::plural('device', $inventoriedDevices) }}, from each device's winget inventory. Packages with devices behind come first.</flux:text>
    </div>
    <flux:separator variant="subtle" />

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search apps or package IDs..." icon="magnifying-glass" class="max-w-sm" />

    <flux:card class="p-0! sm:p-0!">
        @if($packages->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-software-empty>
                <flux:icon name="squares-plus" class="size-10 text-zinc-300 dark:text-zinc-600" />
                @if($search !== '')
                    <flux:heading size="lg">No apps match "{{ $search }}"</flux:heading>
                @else
                    <flux:heading size="lg">No inventory yet</flux:heading>
                    <flux:text class="max-w-md">Run Software Inventory (winget) on your Windows devices, or open a device's Apps tab and press Run now. A schedule every few hours keeps this page current.</flux:text>
                @endif
            </div>
        @else
            <flux:table :paginate="$packages" class="px-4">
                <flux:table.columns>
                    <flux:table.column>App</flux:table.column>
                    <flux:table.column>Installed on</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">Versions</flux:table.column>
                    <flux:table.column>Behind</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($packages as $package)
                        <flux:table.row :key="'package-'.$package->package_id">
                            <flux:table.cell>
                                <a href="{{ route('software.show', ['id' => $package->package_id]) }}" wire:navigate class="block min-w-0">
                                    <span class="block truncate font-semibold text-zinc-800 hover:underline dark:text-white">{{ $package->name }}</span>
                                    <span class="block truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $package->package_id }}</span>
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm" class="tabular-nums">{{ $package->device_count }} {{ Str::plural('device', $package->device_count) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">
                                <flux:text size="sm" class="font-mono">{{ $versionSpread->get($package->package_id) }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($package->outdated_count > 0)
                                    <flux:badge size="sm" color="amber" icon="arrow-up-circle">{{ $package->outdated_count }} behind{{ $package->latest_version ? ' · '.$package->latest_version : '' }}</flux:badge>
                                @elseif($package->source === null)
                                    <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500">Not in winget</flux:text>
                                @else
                                    <flux:text size="sm">Up to date</flux:text>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
