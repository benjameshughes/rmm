@use('App\Enums\TrendMetric')
@use('App\Enums\TrendSort')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Trends</flux:heading>
            <flux:text class="mt-1">{{ $this->trendPeriod->label() }} against {{ Str::lcfirst($this->trendPeriod->previousLabel()) }}, with what was done in the meantime alongside.</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:radio.group wire:model.live="period" variant="segmented" size="sm" data-trends-period>
                @foreach($periods as $option)
                    <flux:radio :value="$option->value" :label="$option->value" wire:key="period-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>

            <flux:radio.group wire:model.live="platform" variant="segmented" size="sm" data-trends-scope>
                @foreach($scopes as $option)
                    <flux:radio :value="$option->value" :label="$option->label()" wire:key="scope-{{ $option->value }}" />
                @endforeach
            </flux:radio.group>
        </div>
    </div>
    <flux:separator variant="subtle" />

    @if($fleet->deviceCount() === 0)
        <flux:card class="flex flex-col items-center gap-3 px-6 py-16 text-center" data-trends-empty>
            <flux:icon name="presentation-chart-line" class="size-10 text-zinc-300 dark:text-zinc-600" />
            <flux:heading size="lg">Not enough reports yet</flux:heading>
            <flux:text class="max-w-md">No {{ $this->trendScope->noun(2) }} have reported for long enough in the {{ Str::lcfirst($this->trendPeriod->label()) }}. Figures appear once they have.</flux:text>
        </flux:card>
    @else
        @unless($fleet->isComparable)
            <flux:callout icon="clock" variant="secondary" data-trends-insufficient>
                <flux:callout.heading>Not enough history for the previous period</flux:callout.heading>
                <flux:callout.text>{{ $this->trendPeriod->previousLabel() }} has too few reports to compare against, so the {{ Str::lcfirst($this->trendPeriod->label()) }} are shown on their own.</flux:callout.text>
            </flux:callout>
        @endunless

        <div class="space-y-3">
            <flux:text size="sm" data-trends-fleet>
                {{ $fleet->deviceCount() }} {{ $this->trendScope->noun($fleet->deviceCount()) }} {{ $fleet->isComparable ? 'that reported in both periods' : 'that reported' }} &middot; figures from {{ $comparison->computedAt->inDisplayTimezone()->format('H:i') }}
            </flux:text>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($heroMetrics as $metric)
                    <x-trends.metric-card :change="$fleet->change($metric)" wire:key="hero-{{ $metric->value }}" />
                @endforeach
            </div>

            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                @foreach($detailMetrics as $metric)
                    <x-trends.metric-card :change="$fleet->change($metric)" compact wire:key="detail-{{ $metric->value }}" />
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        @if($fleet->deviceCount() > 0)
            <div class="space-y-6 xl:col-span-2">
                @foreach($charts as $chart)
                    <flux:card wire:key="trend-chart-{{ $loop->index }}-{{ $period }}-{{ $platform }}">
                        <x-device.metrics.chart :chart="$chart" :time-format="$this->trendPeriod->timeFormat()" compact />
                    </flux:card>
                @endforeach
            </div>
        @endif

        <x-dashboard.section title="In the same period" description="Commands that finished cleanly, newest first. Listed beside the figures, not as their cause." :class="$fleet->deviceCount() === 0 ? 'xl:col-span-3' : ''" data-trends-changes>
            @if($changes->isEmpty())
                <div class="flex items-center gap-3 rounded-lg bg-zinc-50 px-4 py-6 dark:bg-white/5" data-trends-no-changes>
                    <flux:icon name="check-circle" class="size-8 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    <flux:text size="sm">Nothing was installed, removed or run in the {{ Str::lcfirst($this->trendPeriod->label()) }}.</flux:text>
                </div>
            @else
                <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                    @foreach($changes as $change)
                        <x-trends.change-row :change="$change" wire:key="change-{{ $change->key }}" />
                    @endforeach
                </div>
            @endif
        </x-dashboard.section>
    </div>

    @if($rows->isNotEmpty())
        <flux:card class="space-y-2 p-0! sm:p-0!" data-trends-devices>
            <div class="px-4 pt-4 sm:px-6 sm:pt-6">
                <flux:heading size="lg">By device</flux:heading>
                <flux:text size="sm" class="mt-1">{{ $this->listSort->label() }}, {{ $this->listSort->directionLabel($this->listSortDirection) }}. Each PC links to its Metrics tab.</flux:text>
            </div>

            <flux:table class="px-4 sm:px-6">
                <flux:table.columns>
                    <x-device.list.sortable-column :sort="TrendSort::Hostname" :current="$this->listSort" :direction="$this->listSortDirection" />
                    <x-device.list.sortable-column :sort="TrendSort::Ram" :current="$this->listSort" :direction="$this->listSortDirection" align="end" />
                    <x-device.list.sortable-column :sort="TrendSort::Cpu" :current="$this->listSort" :direction="$this->listSortDirection" align="end" class="hidden sm:table-cell" />
                    <x-device.list.sortable-column :sort="TrendSort::Reboots" :current="$this->listSort" :direction="$this->listSortDirection" align="end" class="hidden md:table-cell" />
                    <x-device.list.sortable-column :sort="TrendSort::BlankRate" :current="$this->listSort" :direction="$this->listSortDirection" align="end" class="hidden lg:table-cell" />
                    <flux:table.column class="hidden xl:table-cell">In the same period</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($rows as $row)
                        <flux:table.row :key="'trend-'.$row->device->id" data-trend-device="{{ $row->device->id }}">
                            <flux:table.cell class="max-w-56">
                                <x-device.list.identity :device="$row->device" :href="route('devices.metrics', ['device' => $row->device, 'range' => $this->trendPeriod->metricRange()->value])" />
                                @php($note = $row->note())
                                @if($note)
                                    <flux:text size="xs" class="mt-1 text-amber-600 dark:text-amber-400" data-trend-note>{{ $note }}</flux:text>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <x-trends.change-cell :change="$row->change(TrendMetric::Ram)" />
                            </flux:table.cell>
                            <flux:table.cell align="end" class="hidden sm:table-cell">
                                <x-trends.change-cell :change="$row->change(TrendMetric::Cpu)" />
                            </flux:table.cell>
                            <flux:table.cell align="end" class="hidden md:table-cell">
                                <x-trends.change-cell :change="$row->change(TrendMetric::Reboots)" />
                            </flux:table.cell>
                            <flux:table.cell align="end" class="hidden lg:table-cell">
                                <x-trends.change-cell :change="$row->change(TrendMetric::BlankRate)" />
                            </flux:table.cell>
                            <flux:table.cell class="hidden max-w-72 xl:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    @forelse($row->visibleChangeLabels() as $label)
                                        <flux:badge size="sm" color="zinc" wire:key="trend-{{ $row->device->id }}-label-{{ $loop->index }}">{{ $label }}</flux:badge>
                                    @empty
                                        <flux:text size="xs">Nothing</flux:text>
                                    @endforelse
                                    @php($hiddenChanges = $row->hiddenChangeCount())
                                    @if($hiddenChanges > 0)
                                        <flux:text size="xs">+{{ $hiddenChanges }} more</flux:text>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endif
</div>
