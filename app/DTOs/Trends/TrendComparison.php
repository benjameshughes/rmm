<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\DTOs\MetricChart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the database works out for one comparison, kept briefly in the cache.
 */
final readonly class TrendComparison
{
    /**
     * @param  Collection<int, DeviceTrendStats>  $stats  keyed by device ID
     * @param  Collection<int, array{time: string, ram: ?float, previousRam: ?float, cpu: ?float, previousCpu: ?float}>  $series
     */
    public function __construct(
        public TrendWindow $window,
        public Collection $stats,
        public Collection $series,
        public Carbon $computedAt,
    ) {}

    public function fleet(): FleetTrend
    {
        return FleetTrend::from($this->stats);
    }

    /**
     * Fleet RAM and CPU, the latest period drawn over the one before. A line needs both
     * periods at each point, so buckets missing either are left out while comparing.
     *
     * @return array<int, MetricChart>
     */
    public function charts(): array
    {
        $isComparable = $this->fleet()->isComparable;

        return [
            $this->chart('RAM', 'ram', 'previousRam', 'text-violet-500 dark:text-violet-400', 'bg-violet-500', $isComparable),
            $this->chart('CPU', 'cpu', 'previousCpu', 'text-sky-500 dark:text-sky-400', 'bg-sky-500', $isComparable),
        ];
    }

    private function chart(string $title, string $field, string $previousField, string $color, string $swatch, bool $isComparable): MetricChart
    {
        $rows = $this->series
            ->filter(fn (array $row): bool => $row[$field] !== null && (! $isComparable || $row[$previousField] !== null))
            ->map(fn (array $row): array => ['time' => $row['time'], $field => $row[$field], $previousField => $isComparable ? $row[$previousField] : null])
            ->values();

        return MetricChart::make(
            title: $title,
            format: ['style' => 'unit', 'unit' => 'percent', 'maximumFractionDigits' => 1],
            rows: $rows,
            series: [
                ['field' => $field, 'label' => $this->window->period->label(), 'color' => $color, 'swatch' => $swatch],
                ['field' => $previousField, 'label' => $this->window->period->previousLabel(), 'color' => 'text-zinc-400 dark:text-zinc-500', 'swatch' => 'bg-zinc-400', 'dashed' => true],
            ],
            emptyMessage: 'Not enough reports in this period to draw a chart yet.',
        );
    }
}
