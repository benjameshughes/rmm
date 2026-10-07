<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendMetric;

final readonly class DeviceTrendStats
{
    public function __construct(
        public int $deviceId,
        public PeriodStats $previous,
        public PeriodStats $current,
    ) {}

    /**
     * Enough reports in both periods for a before and after to mean anything.
     */
    public function isComparable(): bool
    {
        return $this->previous->hasEnoughReports() && $this->current->hasEnoughReports();
    }

    public function change(TrendMetric $metric): MetricChange
    {
        return new MetricChange(
            metric: $metric,
            previous: $this->previous->hasEnoughReports() ? $this->previous->value($metric) : null,
            current: $this->current->hasEnoughReports() ? $this->current->value($metric) : null,
        );
    }
}
