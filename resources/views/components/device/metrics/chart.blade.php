@props(['chart', 'timeFormat', 'title' => null, 'empty' => 'Not enough reports in this range to draw a chart yet.', 'compact' => false])

<div {{ $attributes->class('space-y-3') }}>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <flux:heading size="sm">{{ $title ?? $chart->title }}</flux:heading>

        <div class="flex flex-wrap items-center gap-3">
            @foreach($chart->series as $line)
                <div class="flex items-center gap-1.5" wire:key="legend-{{ $line['field'] }}">
                    <span class="size-2 rounded-full {{ $line['swatch'] }}"></span>
                    <flux:text size="sm">{{ $line['label'] }}</flux:text>
                </div>
            @endforeach
        </div>
    </div>

    @if($chart->isDrawable())
        <flux:chart :value="$chart->points()">
            <flux:chart.viewport :class="$compact ? 'h-36' : 'aspect-[3/1] min-h-40'">
                <flux:chart.svg>
                    @foreach($chart->series as $line)
                        <flux:chart.line :field="$line['field']" class="{{ $line['color'] }}" curve="none" />
                    @endforeach

                    <flux:chart.axis axis="x" field="time" :format="$timeFormat">
                        <flux:chart.axis.tick />
                        <flux:chart.axis.line />
                    </flux:chart.axis>

                    <flux:chart.axis axis="y" :format="$chart->format">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>

                    <flux:chart.cursor />
                </flux:chart.svg>
            </flux:chart.viewport>

            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="time" :format="['month' => 'short', 'day' => 'numeric', 'hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false]" />
                @foreach($chart->series as $line)
                    <flux:chart.tooltip.value :field="$line['field']" :label="$line['label']" :format="$chart->format" />
                @endforeach
            </flux:chart.tooltip>
        </flux:chart>
    @else
        <div @class(['flex items-center justify-center rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700', 'h-36' => $compact, 'min-h-40' => ! $compact])>
            <flux:text size="sm" class="max-w-md text-center">{{ $chart->emptyMessage ?? $empty }}</flux:text>
        </div>
    @endif
</div>
