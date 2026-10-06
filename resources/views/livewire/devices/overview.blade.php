<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Overview">
    <x-device.stats.summary :metric="$metric" :disk="$fullestDisk" :swap-label="$swapLabel" :missing-swap="$missingSwap" />

    <x-device.overview.open-alerts :alerts="$openAlerts" />

    <div class="grid gap-6 lg:grid-cols-2" data-overview-charts>
        @foreach($charts as $chart)
            <flux:card wire:key="overview-chart-{{ $loop->index }}">
                <x-device.metrics.chart :chart="$chart" :time-format="$chartTimeFormat" :title="$chart->title.' · '.$chartRange" compact />
            </flux:card>
        @endforeach
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-device.overview.system-glance :device="$device" :inventory="$inventory" :collected="$inventoryCollected" />
        <x-device.linux-health :metric="$metric" />

        <div class="space-y-6">
            <x-device.disk-storage :disks="$disks" :busy="$metric?->diskBusyForHumans()" />
            <x-device.netdata-alerts :metric="$metric" />
        </div>
    </div>

    <x-device.overview.recent-commands :device="$device" :commands="$recentCommands" />
</x-device.shell>
