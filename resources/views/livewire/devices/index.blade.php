@use('App\Enums\DeviceListFilter')
@use('App\Enums\DeviceListSort')

<div class="space-y-6" x-data x-bind:class="{ 'pb-20': $wire.selectedDevices.length > 0 }">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Devices</flux:heading>
        <flux:button as="a" :href="route('devices.pending')" wire:navigate>
            Pending Approvals
        </flux:button>
    </div>
    <flux:separator variant="subtle" />

    <div class="flex flex-wrap items-center gap-2" data-summary-strip>
        <x-device.list.summary-chip label="All" :value="$summary['total']" :active="$this->listFilter === null" wire:click="filterByStatus('')" />
        <x-device.list.summary-chip :label="DeviceListFilter::Online->label()" :value="$summary['online']" dot="bg-green-500" :active="$this->listFilter === DeviceListFilter::Online" wire:click="filterByStatus('{{ DeviceListFilter::Online->value }}')" />
        <x-device.list.summary-chip :label="DeviceListFilter::PoweringOff->label()" :value="$summary['poweringOff']" dot="bg-amber-500" :active="$this->listFilter === DeviceListFilter::PoweringOff" wire:click="filterByStatus('{{ DeviceListFilter::PoweringOff->value }}')" />
        <x-device.list.summary-chip :label="DeviceListFilter::Offline->label()" :value="$summary['offline']" dot="bg-red-500" :active="$this->listFilter === DeviceListFilter::Offline" wire:click="filterByStatus('{{ DeviceListFilter::Offline->value }}')" />
        <x-device.list.summary-chip :label="DeviceListFilter::Outdated->label()" :value="$summary['outdated']" dot="bg-sky-500" :active="$this->listFilter === DeviceListFilter::Outdated" wire:click="filterByStatus('{{ DeviceListFilter::Outdated->value }}')" />
        <x-device.list.summary-chip label="Open alerts" :value="$summary['openAlerts']" dot="bg-rose-500" :href="route('alerts.index')" />
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search hostname, IP, OS..." class="max-w-sm" icon="magnifying-glass" />
        <flux:select wire:model.live="groupFilter" placeholder="All Groups" class="max-w-48">
            <flux:select.option value="">All Groups</flux:select.option>
            @foreach($groups as $group)
                <flux:select.option value="{{ $group->id }}">{{ $group->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="tagFilter" placeholder="All Tags" class="max-w-48">
            <flux:select.option value="">All Tags</flux:select.option>
            @foreach($tags as $tag)
                <flux:select.option value="{{ $tag->id }}">{{ $tag->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @if($isFiltered)
            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>

    <flux:card class="p-0! sm:p-0! overflow-x-auto">
        @if($devices->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-16 text-center">
                <flux:icon name="server-stack" class="size-10 text-zinc-300 dark:text-zinc-600" />
                @if($isFiltered)
                    <flux:heading size="lg">No devices match these filters</flux:heading>
                    <flux:text>Try a different search, or clear the filters to see the whole fleet.</flux:text>
                    <flux:button size="sm" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
                @else
                    <flux:heading size="lg">No devices yet</flux:heading>
                    <flux:text>Install the agent on a PC, then approve it from Pending.</flux:text>
                    <flux:button size="sm" variant="primary" icon="arrow-down-tray" :href="route('devices.agent')" wire:navigate>Install the agent</flux:button>
                @endif
            </div>
        @else
            <flux:table :paginate="$devices" class="px-4">
                <flux:table.columns>
                    <flux:table.column class="w-8">
                        <flux:checkbox wire:model.live="selectAll" />
                    </flux:table.column>
                    <x-device.list.sortable-column :sort="DeviceListSort::Hostname" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Status" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Group" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden xl:table-cell" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Cpu" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden lg:table-cell" align="end" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Ram" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden lg:table-cell" align="end" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Disk" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden lg:table-cell" align="end" />
                    <x-device.list.sortable-column :sort="DeviceListSort::Agent" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden 2xl:table-cell" />
                    <x-device.list.sortable-column :sort="DeviceListSort::LastSeen" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden sm:table-cell" />
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($devices as $device)
                        <x-device.list.row :device="$device" :latest-version="$latestAgentVersion" wire:key="device-{{ $device->id }}" />
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>

    <flux:modal wire:model="showBulkScriptModal" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Run Script</flux:heading>
                <flux:text class="mt-2">Execute a script on {{ count($selectedDevices) }} {{ Str::plural('device', count($selectedDevices)) }}.</flux:text>
            </div>

            <flux:select wire:model.live="bulkScriptId" label="Script" placeholder="Select a script..." variant="listbox" searchable>
                @foreach($scripts as $script)
                    <flux:select.option value="{{ $script->id }}">{{ $script->name }} ({{ $script->platform->name }})</flux:select.option>
                @endforeach
            </flux:select>

            <x-script.parameter-inputs :parameters="$this->parameterFields" />

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showBulkScriptModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="bulkRunScript" variant="primary" icon="play">Execute</flux:button>
            </div>
        </div>
    </flux:modal>

    <x-device.list.bulk-bar :groups="$groups" :tags="$tags" />

    <livewire:software.install-software />
</div>
