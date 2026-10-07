<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendMetric;

/**
 * One device's figures for one period, aggregated by the database.
 */
final readonly class PeriodStats
{
    public function __construct(
        public int $reports = 0,
        public int $blankReports = 0,
        public ?float $cpu = null,
        public ?float $cpuPeak = null,
        public ?float $ram = null,
        public ?float $swapMib = null,
        public ?float $diskBusy = null,
        public int $reboots = 0,
        public float $onlineHours = 0.0,
    ) {}

    public static function fromRow(object $row): self
    {
        $optionalFloat = fn (mixed $value): ?float => $value === null ? null : (float) $value;

        return new self(
            reports: (int) $row->reports,
            blankReports: (int) $row->blank_reports,
            cpu: $optionalFloat($row->cpu),
            cpuPeak: $optionalFloat($row->cpu_peak),
            ram: $optionalFloat($row->ram),
            swapMib: $optionalFloat($row->swap_mib),
            diskBusy: $optionalFloat($row->disk_busy),
            reboots: (int) $row->reboots,
            onlineHours: (int) $row->online_buckets * config('trends.online_bucket_seconds') / 3600,
        );
    }

    public function hasEnoughReports(): bool
    {
        return $this->reports >= config('trends.min_reports');
    }

    public function blankRate(): ?float
    {
        return $this->reports === 0 ? null : $this->blankReports / $this->reports * 100;
    }

    public function value(TrendMetric $metric): ?float
    {
        return match ($metric) {
            TrendMetric::Ram => $this->ram,
            TrendMetric::Cpu => $this->cpu,
            TrendMetric::Reboots => (float) $this->reboots,
            TrendMetric::BlankRate => $this->blankRate(),
            TrendMetric::CpuPeak => $this->cpuPeak,
            TrendMetric::DiskBusy => $this->diskBusy,
            TrendMetric::Swap => $this->swapMib,
            TrendMetric::OnlineHours => $this->onlineHours,
        };
    }
}
