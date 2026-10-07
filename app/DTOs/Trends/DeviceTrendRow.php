<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendMetric;
use App\Enums\TrendSort;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * One device on the Trends page: its before and after, and what was done to it in the period.
 */
final readonly class DeviceTrendRow
{
    /**
     * @param  Collection<int, string>  $changeLabels
     */
    public function __construct(
        public Device $device,
        public DeviceTrendStats $stats,
        public Collection $changeLabels,
    ) {}

    public function change(TrendMetric $metric): MetricChange
    {
        return $this->stats->change($metric);
    }

    public function note(): ?string
    {
        return match (true) {
            $this->stats->isComparable() => null,
            ! $this->stats->current->hasEnoughReports() => 'Too few reports in this period',
            default => 'Not enough history for the previous period',
        };
    }

    /**
     * Rows that cannot be compared sort last whichever way the column runs.
     *
     * @return array{0: int, 1: float|string}
     */
    public function sortKey(TrendSort $sort): array
    {
        $metric = $sort->metric();

        if ($metric === null) {
            return [0, mb_strtolower($this->device->hostname)];
        }

        $difference = $this->change($metric)->difference();

        return $difference === null ? [1, 0.0] : [0, $difference];
    }

    /** @return Collection<int, string> */
    public function visibleChangeLabels(): Collection
    {
        return $this->changeLabels->take(config('trends.device_change_labels'));
    }

    public function hiddenChangeCount(): int
    {
        return max(0, $this->changeLabels->count() - config('trends.device_change_labels'));
    }
}
