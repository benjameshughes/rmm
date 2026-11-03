<div class="space-y-8">
    <header class="flex h-8 items-baseline justify-between gap-4">
        <flux:heading size="lg" level="1">Dashboard</flux:heading>
        <span class="text-xs text-zinc-400" data-updated-at>Updated {{ $updatedAt }}</span>
    </header>

    <div class="grid grid-cols-2 items-end gap-x-8 gap-y-6 md:grid-cols-3 xl:grid-cols-5" data-hero>
        <a href="{{ route('devices.index', ['statusFilter' => 'online']) }}" wire:navigate class="flex items-end gap-4" data-hero-online>
            <div class="relative size-22 shrink-0">
                <x-dashboard.ring :ring="$onlineRing" class="size-22" />
                <span class="absolute inset-0 flex items-center justify-center text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $hero->online }}<span class="text-sm font-normal text-zinc-400">/{{ $hero->total }}</span></span>
            </div>
            <span class="pb-1 text-[11px] font-medium uppercase tracking-wider text-zinc-500">Online</span>
        </a>

        <x-dashboard.hero-tile label="Offline" :value="$hero->offline" :value-class="$hero->offlineClass()" :href="route('devices.index', ['statusFilter' => 'offline'])" data-hero-offline>
            <x-slot:aside>
                @foreach($offlineNames as $device)
                    <a href="{{ route('devices.show', $device) }}" wire:navigate class="text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white" wire:key="offline-{{ $device->id }}">{{ $device->hostname }}</a>@unless($loop->last), @endunless
                @endforeach
                @if($hiddenOfflineCount > 0)
                    <span>+{{ $hiddenOfflineCount }} more</span>
                @endif
            </x-slot:aside>
        </x-dashboard.hero-tile>

        <x-dashboard.hero-tile label="Open alerts" :value="$hero->openAlerts" :value-class="$hero->openAlertsClass()" :href="route('alerts.index')" data-hero-alerts>
            <x-slot:chart>
                <flux:chart :value="$alertMiniBars" class="h-8 w-full">
                    <flux:chart.svg gutter="0">
                        <flux:chart.stack>
                            <flux:chart.bar field="calm" class="text-zinc-300 dark:text-zinc-600" />
                            <flux:chart.bar field="hot" class="text-red-500" />
                        </flux:chart.stack>
                    </flux:chart.svg>
                </flux:chart>
            </x-slot:chart>
            <x-slot:aside>
                <span class="{{ $hero->alertTrend->direction()->textClass() }}">{{ $hero->alertTrend->deltaLabel() }}</span>
            </x-slot:aside>
        </x-dashboard.hero-tile>

        <x-dashboard.hero-tile :label="$disksLabel" :value="$hero->fullDisks" :value-class="$hero->fullDisksClass()" :href="route('devices.index')">
            <x-slot:chart>
                <x-dashboard.meter :segments="$heroMeters['disks']" />
            </x-slot:chart>
        </x-dashboard.hero-tile>

        <x-dashboard.hero-tile label="Failed units" :value="$hero->failedUnits" :value-class="$hero->failedUnitsClass()" :href="route('devices.index')">
            <x-slot:chart>
                <x-dashboard.meter :segments="$heroMeters['units']" />
            </x-slot:chart>
        </x-dashboard.hero-tile>
    </div>

    <x-dashboard.fleet-grid :groups="$fleetGrid" />

    <div class="grid grid-cols-1 gap-x-12 gap-y-8 lg:grid-cols-12">
        <section class="min-w-0 bg-zinc-50 p-4 lg:col-span-7 dark:bg-white/[0.03]" data-needs-attention>
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Needs attention <span class="ms-1 font-normal tabular-nums text-zinc-400">{{ $needsAttention->count() }}</span></h2>

            @if($attentionRows->isEmpty())
                <div class="flex h-8 items-center gap-3 text-sm text-zinc-800 dark:text-zinc-100" data-all-clear>
                    <span class="size-1.5 rounded-[1px] bg-green-500"></span>
                    All clear. Nothing needs you.
                </div>
            @else
                @foreach($attentionRows as $row)
                    <x-dashboard.attention-line :row="$row" wire:key="attention-{{ $row->device->id }}" />
                @endforeach
                @if($hiddenAttentionCount > 0)
                    <a href="{{ route('devices.index') }}" wire:navigate class="mt-1 inline-block text-xs text-zinc-400 hover:text-zinc-800 dark:hover:text-white" data-attention-more>+{{ $hiddenAttentionCount }} more &rarr;</a>
                @endif
            @endif

            @if($worthKnowingRows->isNotEmpty())
                <div class="mt-4" data-worth-knowing>
                    <p class="text-xs text-zinc-500">Worth knowing</p>
                    @foreach($worthKnowingRows as $row)
                        <x-dashboard.attention-line :row="$row" muted wire:key="worth-knowing-{{ $row->device->id }}" />
                    @endforeach
                </div>
            @endif
        </section>

        <div class="min-w-0 space-y-8 lg:col-span-5">
            <x-dashboard.panel title="Alerts · 24h" :href="route('alerts.index')" link-label="All alerts" data-alerts-chart>
                @if($isQuietDay)
                    <div class="flex h-44 flex-col justify-end gap-2">
                        <div class="h-px bg-zinc-200 dark:bg-zinc-700"></div>
                        <span class="text-xs text-zinc-400">Quiet day.</span>
                    </div>
                @else
                    <flux:chart :value="$alertsPerBucket" class="h-44">
                        <flux:chart.svg gutter="4 0 16 20">
                            <flux:chart.stack width="70%">
                                <flux:chart.bar field="warning" class="text-amber-400" />
                                <flux:chart.bar field="critical" class="text-red-500" />
                            </flux:chart.stack>
                            <flux:chart.axis axis="x" field="time" :format="['hour' => '2-digit', 'hour12' => false]">
                                <flux:chart.axis.tick class="text-zinc-400" />
                            </flux:chart.axis>
                            <flux:chart.axis axis="y">
                                <flux:chart.axis.grid class="text-zinc-100 dark:text-zinc-800" />
                                <flux:chart.axis.tick class="text-zinc-400" />
                            </flux:chart.axis>
                        </flux:chart.svg>
                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="time" :format="['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false]" />
                            <flux:chart.tooltip.value field="critical" label="Critical" />
                            <flux:chart.tooltip.value field="warning" label="Warning" />
                        </flux:chart.tooltip>
                    </flux:chart>
                @endif
            </x-dashboard.panel>

            <x-dashboard.panel title="Fleet load · 24h" :href="route('trends.index')" link-label="Trends" data-fleet-load>
                <x-slot:titleExtra>
                    <span class="ms-2 text-xs font-normal"><span class="text-zinc-800 dark:text-zinc-100">CPU</span> <span class="text-zinc-400">·</span> <span class="text-zinc-400">RAM</span></span>
                </x-slot:titleExtra>
                <flux:chart :value="$fleetLoad" class="h-44">
                    <flux:chart.svg gutter="4 0 16 28">
                        <flux:chart.line field="threshold" class="text-amber-500" curve="none" stroke-width="1" stroke-dasharray="4 4" />
                        <flux:chart.line field="ram" class="text-zinc-400" curve="none" stroke-width="1.5" />
                        <flux:chart.line field="cpu" class="text-zinc-800 dark:text-zinc-100" curve="none" stroke-width="2" />
                        <flux:chart.axis axis="x" field="time" :format="['hour' => '2-digit', 'hour12' => false]">
                            <flux:chart.axis.tick class="text-zinc-400" />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" :format="['style' => 'unit', 'unit' => 'percent']">
                            <flux:chart.axis.grid class="text-zinc-100 dark:text-zinc-800" />
                            <flux:chart.axis.tick class="text-zinc-400" />
                        </flux:chart.axis>
                        <flux:chart.cursor />
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="time" :format="['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false]" />
                        <flux:chart.tooltip.value field="cpu" label="CPU" suffix="%" />
                        <flux:chart.tooltip.value field="ram" label="RAM" suffix="%" />
                    </flux:chart.tooltip>
                </flux:chart>
            </x-dashboard.panel>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-x-12 gap-y-8 lg:grid-cols-3">
        <x-dashboard.panel title="Disks" :href="route('devices.index')" :link-label="$diskLinkLabel" data-disk-ring>
            <div class="flex items-center gap-4">
                <flux:tooltip :content="$diskRing->tooltip()">
                    <div class="relative size-30 shrink-0">
                        <x-dashboard.ring :ring="$diskRing" class="size-30" />
                        <span class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-semibold leading-none tabular-nums {{ $disksOverWarningClass }}">{{ $disksOverWarning }}</span>
                            <span class="text-xs text-zinc-400">{{ $disksRingCaption }}</span>
                        </span>
                    </div>
                </flux:tooltip>
                <div class="min-w-0 flex-1">
                    @forelse($fullestDisks as $row)
                        <x-dashboard.disk-row :device="$row['device']" :disk="$row['disk']" :meter-color="$row['meterColor']" wire:key="disk-{{ $row['device']->id }}" />
                    @empty
                        <p class="text-xs text-zinc-400">No device has reported its disks yet.</p>
                    @endforelse
                </div>
            </div>
        </x-dashboard.panel>

        <x-dashboard.panel title="Agents" :href="route('devices.index', ['statusFilter' => 'outdated'])" :link-label="$agentsBehindLabel" data-agent-ring>
            <div class="flex items-center gap-6">
                <flux:tooltip :content="$agentRing->tooltip()">
                    <div class="relative size-30 shrink-0">
                        <x-dashboard.ring :ring="$agentRing" class="size-30" />
                        <span class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-semibold leading-none tabular-nums text-zinc-900 dark:text-white">{{ $agentsCurrent }}<span class="text-sm font-normal text-zinc-400">/{{ $agentRing->total() }}</span></span>
                            <span class="text-xs text-zinc-400">current</span>
                        </span>
                    </div>
                </flux:tooltip>
                <dl class="min-w-0 flex-1" data-agent-breakdown>
                    @foreach($agentRing->segments as $segment)
                        <div class="flex items-center gap-2 py-1.5 text-sm" wire:key="agent-segment-{{ $segment->label }}">
                            <span class="size-2 shrink-0 rounded-[1px] {{ $segment->ink->fillClass() }}"></span>
                            <dt class="flex-1 text-zinc-500 dark:text-zinc-400">{{ $segment->label }}</dt>
                            <dd class="font-medium tabular-nums text-zinc-800 dark:text-zinc-100">{{ $segment->value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </x-dashboard.panel>

        <x-dashboard.panel title="Busiest now">
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_3rem_3rem] gap-3 text-[11px] text-zinc-400">
                <span>Device</span><span>CPU 24h</span><span class="text-right">CPU</span><span class="text-right">RAM</span>
            </div>
            @forelse($busiestRows as $row)
                <x-dashboard.busy-line :row="$row" wire:key="busy-{{ $row->device->id }}" />
            @empty
                <p class="text-xs text-zinc-400">No device is online right now.</p>
            @endforelse
        </x-dashboard.panel>
    </div>
</div>
