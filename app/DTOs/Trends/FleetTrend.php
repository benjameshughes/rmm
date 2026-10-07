<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendMetric;
use Illuminate\Support\Collection;

/**
 * The fleet before and after, over the devices with enough reports in both periods. Usage is
 * the mean of each device's own average, reboots a total and the blank rate a share of every report.
 * With no such device yet, the latest period stands alone and nothing is compared.
 */
final readonly class FleetTrend
{
    /**
     * @param  array<int, int>  $deviceIds  the devices measured
     * @param  array<string, MetricChange>  $changes  keyed by TrendMetric value
     */
    public function __construct(
        public array $deviceIds,
        public bool $isComparable,
        public array $changes,
    ) {}

    /**
     * @param  Collection<int, DeviceTrendStats>  $stats
     */
    public static function from(Collection $stats): self
    {
        $comparable = $stats->filter(fn (DeviceTrendStats $device): bool => $device->isComparable());
        $isComparable = $comparable->isNotEmpty();
        $measured = $isComparable ? $comparable : $stats->filter(fn (DeviceTrendStats $device): bool => $device->current->hasEnoughReports());

        return new self(
            deviceIds: $measured->keys()->all(),
            isComparable: $isComparable,
            changes: collect(TrendMetric::cases())
                ->mapWithKeys(fn (TrendMetric $metric): array => [$metric->value => new MetricChange(
                    metric: $metric,
                    previous: $isComparable ? self::aggregate($metric, $measured->map(fn (DeviceTrendStats $device): PeriodStats => $device->previous)) : null,
                    current: self::aggregate($metric, $measured->map(fn (DeviceTrendStats $device): PeriodStats => $device->current)),
                )])
                ->all(),
        );
    }

    public function deviceCount(): int
    {
        return count($this->deviceIds);
    }

    public function change(TrendMetric $metric): MetricChange
    {
        return $this->changes[$metric->value];
    }

    /**
     * @param  Collection<int, PeriodStats>  $periods
     */
    private static function aggregate(TrendMetric $metric, Collection $periods): ?float
    {
        if ($periods->isEmpty()) {
            return null;
        }

        return match ($metric) {
            TrendMetric::Reboots => (float) $periods->sum(fn (PeriodStats $period): int => $period->reboots),
            TrendMetric::BlankRate => $periods->sum(fn (PeriodStats $period): int => $period->blankReports) / max(1, $periods->sum(fn (PeriodStats $period): int => $period->reports)) * 100,
            default => $periods
                ->map(fn (PeriodStats $period): ?float => $period->value($metric))
                ->filter(fn (?float $value): bool => $value !== null)
                ->avg(),
        };
    }
}
