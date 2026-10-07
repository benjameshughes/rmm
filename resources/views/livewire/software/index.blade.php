@use('App\Enums\SoftwareCoverage')
@use('App\Enums\SoftwareSort')
@use('App\Enums\SoftwareSource')

<div class="space-y-6" x-data x-bind:class="{ 'pb-20': $wire.selectedPackages.length > 0 }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Software</flux:heading>
            <flux:text class="mt-1">Apps installed across {{ $inventoriedDevices }} {{ Str::plural('device', $inventoriedDevices) }}, from each device's winget inventory. Packages with devices behind come first.</flux:text>
        </div>
        <flux:button variant="primary" icon="arrow-down-tray" x-on:click="$dispatch('open-install-software')" data-open-install-software>Install software</flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-4" data-software-filters>
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search apps or package IDs..." icon="magnifying-glass" class="max-w-sm" />
        <flux:select wire:model.live="source" class="max-w-48">
            <flux:select.option value="">All sources</flux:select.option>
            @foreach(SoftwareSource::cases() as $softwareSource)
                <flux:select.option value="{{ $softwareSource->value }}">{{ $softwareSource->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="coverage" class="max-w-48">
            <flux:select.option value="">Installed anywhere</flux:select.option>
            @foreach(SoftwareCoverage::cases() as $softwareCoverage)
                <flux:select.option value="{{ $softwareCoverage->value }}">{{ $softwareCoverage->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="groupFilter" class="max-w-48">
            <flux:select.option value="">All Groups</flux:select.option>
            @foreach($groups as $group)
                <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="tagFilter" class="max-w-48">
            <flux:select.option value="">All Tags</flux:select.option>
            @foreach($tags as $tag)
                <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:switch wire:model.live="isOutdatedOnly" label="Updates available" align="left" />
        @if($this->filters->isFiltered())
            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>

    <flux:card class="p-0! sm:p-0! overflow-x-auto">
        @if($packages->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-software-empty>
                <flux:icon name="squares-plus" class="size-10 text-zinc-300 dark:text-zinc-600" />
                @if($this->filters->isFiltered())
                    <flux:heading size="lg">No apps match these filters</flux:heading>
                    <flux:button size="sm" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
                @else
                    <flux:heading size="lg">No inventory yet</flux:heading>
                    <flux:text class="max-w-md">Run Software Inventory (winget) on your Windows devices, or open a device's Apps tab and press Run now. A schedule every few hours keeps this page current.</flux:text>
                @endif
            </div>
        @else
            <flux:table :paginate="$packages" class="px-4">
                <flux:table.columns>
                    <flux:table.column class="w-8">
                        <flux:checkbox wire:model.live="selectAll" aria-label="Select every app on this page" />
                    </flux:table.column>
                    <x-device.list.sortable-column :sort="SoftwareSort::Name" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="SoftwareSort::Devices" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="SoftwareSort::Versions" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden md:table-cell" />
                    <x-device.list.sortable-column :sort="SoftwareSort::Behind" :current="$this->listSort" :direction="$this->listSortDirection" />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($packages as $package)
                        <flux:table.row :key="'package-'.$package->package_id">
                            <flux:table.cell>
                                <flux:checkbox wire:model="selectedPackages" value="{{ $package->package_id }}" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="min-w-0 space-y-1">
                                    <a href="{{ route('software.show', ['id' => $package->package_id]) }}" wire:navigate class="block min-w-0">
                                        <span class="block truncate font-semibold text-zinc-800 hover:underline dark:text-white">{{ $package->name }}</span>
                                        <span class="block truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $package->package_id }}</span>
                                    </a>
                                    <x-software.activity :commands="$packageCommands->get($package->package_id)" />
                                </div>
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

    <x-bulk-bar selection="selectedPackages" noun="app">
        <flux:button size="sm" variant="ghost" icon="arrow-up-circle" wire:click="planPackageCommands('{{ App\Enums\PackageAction::Upgrade->value }}')" data-bulk-upgrade>
            <span class="max-sm:sr-only">Upgrade</span>
        </flux:button>
        <flux:button size="sm" variant="ghost" icon="trash" class="text-red-400! hover:text-red-300!" wire:click="planPackageCommands('{{ App\Enums\PackageAction::Uninstall->value }}')" data-bulk-uninstall>
            <span class="max-sm:sr-only">Uninstall everywhere</span>
        </flux:button>
    </x-bulk-bar>

    <x-software.package-command-modal :plan="$this->packageCommandPlan" />

    <livewire:software.install-software />
</div>
