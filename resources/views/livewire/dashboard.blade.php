@use('App\Enums\DeviceListFilter')

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">Dashboard</flux:heading>
        <flux:text class="mt-1">{{ $summary['total'] }} {{ Str::plural('device', $summary['total']) }}, updated live.</flux:text>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-device.list.summary-card label="Devices" :value="$summary['total']" icon="server" :href="route('devices.index')" />
        <x-device.list.summary-card :label="DeviceListFilter::Online->label()" :value="$summary['online']" icon="signal" tone="text-green-500" :href="route('devices.index', ['statusFilter' => DeviceListFilter::Online->value])" />
        <x-device.list.summary-card :label="DeviceListFilter::PoweringOff->label()" :value="$summary['poweringOff']" icon="moon" tone="text-amber-500" :href="route('devices.index', ['statusFilter' => DeviceListFilter::PoweringOff->value])" />
        <x-device.list.summary-card :label="DeviceListFilter::Offline->label()" :value="$summary['offline']" icon="signal-slash" tone="text-red-500" :href="route('devices.index', ['statusFilter' => DeviceListFilter::Offline->value])" />
        <x-device.list.summary-card label="Managed" :value="$summary['managed']" icon="command-line" :href="route('devices.index')" />
        <x-device.list.summary-card label="Monitor only" :value="$summary['monitorOnly']" icon="eye" :href="route('devices.index')" />
    </div>

    <x-dashboard.section title="Needs attention" description="Full disks, open alerts, devices that went quiet and old agents, worst first.">
        @if($needsAttention->isEmpty())
            <div class="flex items-center gap-3 rounded-lg bg-green-50 px-4 py-6 dark:bg-green-500/10" data-all-clear>
                <flux:icon name="check-circle" class="size-8 shrink-0 text-green-500" />
                <div>
                    <flux:heading>All clear</flux:heading>
                    <flux:text size="sm">No full disks, open alerts, silent devices or old agents.</flux:text>
                </div>
            </div>
        @else
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                @foreach($needsAttention as $attention)
                    <x-dashboard.attention-row :attention="$attention" :latest-version="$latestAgentVersion" wire:key="attention-{{ $attention->device->id }}" />
                @endforeach
            </div>
        @endif
    </x-dashboard.section>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-dashboard.section title="Disk space across the fleet" description="Each device's fullest disk." :href="route('devices.index')" :link-label="'All '.$diskCount.' devices'">
            @forelse($fullestDisks as $row)
                <x-dashboard.disk-row :device="$row['device']" :disk="$row['disk']" wire:key="disk-{{ $row['device']->id }}" />
            @empty
                <flux:text>No device has reported its disks yet.</flux:text>
            @endforelse
        </x-dashboard.section>

        <x-dashboard.section title="Busiest right now" description="Online devices by CPU or RAM, whichever is higher.">
            <div class="space-y-3">
                @forelse($busiest as $device)
                    <div class="grid grid-cols-[minmax(0,10rem)_1fr] items-center gap-3" wire:key="busy-{{ $device->id }}">
                        <a href="{{ route('devices.metrics', $device) }}" wire:navigate class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $device->hostname }}</a>
                        <div class="space-y-1">
                            <x-device.usage-meter label="CPU" :value="$device->latestMetric->cpuRoundedForHumans()" :percent="$device->latestMetric->cpu" :color="$device->latestMetric->cpuBarColor()" />
                            <x-device.usage-meter label="RAM" :value="$device->latestMetric->ramRoundedForHumans()" :percent="$device->latestMetric->ram" :color="$device->latestMetric->ramBarColor()" />
                        </div>
                    </div>
                @empty
                    <flux:text>No device is online right now.</flux:text>
                @endforelse
            </div>
        </x-dashboard.section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-dashboard.section title="Recent alerts" :href="route('alerts.index')">
            <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                @forelse($recentAlerts as $alert)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="recent-alert-{{ $alert->id }}">
                        <flux:badge size="sm" :color="$alert->severity->color()">{{ $alert->severity->label() }}</flux:badge>
                        <div class="min-w-0 flex-1">
                            <flux:text size="sm" class="truncate font-medium text-zinc-800 dark:text-white">{{ $alert->device?->hostname ?? $alert->message }}</flux:text>
                            <flux:text size="xs" class="truncate">{{ $alert->conditionLabel() }} &middot; {{ $alert->triggered_at->diffForHumans() }}</flux:text>
                        </div>
                        <flux:badge size="sm" :color="$alert->status->color()">{{ $alert->status->label() }}</flux:badge>
                    </div>
                @empty
                    <flux:text>No alerts yet.</flux:text>
                @endforelse
            </div>
        </x-dashboard.section>

        @can('viewAny', App\Models\AuditLog::class)
            <x-dashboard.section title="Recent activity" :href="route('audit.index')">
                <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                    @forelse($recentActivity as $auditLog)
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="activity-{{ $auditLog->id }}">
                            <flux:badge size="sm" :color="$auditLog->action->color()">{{ $auditLog->action->label() }}</flux:badge>
                            <flux:text size="sm" class="min-w-0 flex-1 truncate">{{ $auditLog->summary() }}</flux:text>
                            <flux:text size="xs">{{ $auditLog->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <flux:text>Nothing in the audit log yet.</flux:text>
                    @endforelse
                </div>
            </x-dashboard.section>
        @endcan
    </div>
</div>
