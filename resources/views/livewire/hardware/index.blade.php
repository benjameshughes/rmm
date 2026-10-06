@use('App\Enums\HardwareSort')

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Hardware</flux:heading>
        <flux:text class="mt-1">
            Each device's specs from its latest system inventory.
            @if($devicesWithoutInventory > 0)
                <span data-hardware-missing>{{ $devicesWithoutInventory }} {{ Str::plural('device', $devicesWithoutInventory) }} {{ $devicesWithoutInventory === 1 ? 'has' : 'have' }} no system inventory yet.</span>
            @endif
        </flux:text>
    </div>
    <flux:separator variant="subtle" />

    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search PCs, models, service tags, CPUs..." icon="magnifying-glass" class="max-w-sm" />

    <flux:card class="p-0! sm:p-0!">
        @if($rows->isEmpty())
            <div class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-hardware-empty>
                <flux:icon name="cpu-chip" class="size-10 text-zinc-300 dark:text-zinc-600" />
                @if($search !== '')
                    <flux:heading size="lg">No devices match "{{ $search }}"</flux:heading>
                @else
                    <flux:heading size="lg">No inventory yet</flux:heading>
                    <flux:text class="max-w-md">Run System Inventory on your Windows devices, or open a device's System tab and press Run now.</flux:text>
                @endif
            </div>
        @else
            <flux:table :paginate="$rows" class="px-4">
                <flux:table.columns>
                    <x-device.list.sortable-column :sort="HardwareSort::Hostname" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="HardwareSort::Model" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <flux:table.column class="hidden md:table-cell">Service tag</flux:table.column>
                    <flux:table.column class="hidden lg:table-cell">CPU</flux:table.column>
                    <x-device.list.sortable-column :sort="HardwareSort::Ram" :current="$this->listSort" :direction="$this->listSortDirection" align="end" />
                    <flux:table.column class="hidden lg:table-cell">Disk</flux:table.column>
                    <flux:table.column class="hidden xl:table-cell">Windows</flux:table.column>
                    <flux:table.column class="hidden xl:table-cell">Monitors</flux:table.column>
                    <x-device.list.sortable-column :sort="HardwareSort::Collected" :current="$this->listSort" :direction="$this->listSortDirection" class="hidden sm:table-cell" />
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($rows as $row)
                        <flux:table.row :key="'hardware-'.$row->device->id">
                            <flux:table.cell class="max-w-56">
                                <x-device.list.identity :device="$row->device" :href="route('devices.system', $row->device)" />
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:text size="sm">{{ $row->model ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">
                                <flux:text size="sm" class="font-mono">{{ $row->serviceTag ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">
                                <flux:text size="sm" class="max-w-56 truncate" :title="$row->processor">{{ $row->processor ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:text size="sm" class="tabular-nums">{{ $row->ram ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden lg:table-cell">
                                <flux:text size="sm" class="tabular-nums">{{ $row->disk ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden xl:table-cell">
                                <flux:text size="sm">{{ $row->windowsEdition ?? '—' }}</flux:text>
                                <flux:text size="xs" class="font-mono">{{ $row->windowsBuild }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden xl:table-cell">
                                <flux:text size="sm" class="max-w-56 truncate" :title="$row->monitors">{{ $row->monitors ?? '—' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell class="hidden sm:table-cell">
                                <flux:text size="sm" :title="$row->collectedAt">{{ $row->collectedForHumans }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
</div>
