<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far back the Trends page looks: the latest stretch against the same length just before it.
 */
enum TrendPeriod: string
{
    case Day = '24h';
    case Week = '7d';
    case Month = '30d';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Last 24 hours',
            self::Week => 'Last 7 days',
            self::Month => 'Last 30 days',
        };
    }

    public function previousLabel(): string
    {
        return match ($this) {
            self::Day => 'The 24 hours before',
            self::Week => 'The 7 days before',
            self::Month => 'The 30 days before',
        };
    }

    public function hours(): int
    {
        return config("trends.periods.{$this->value}.hours");
    }

    public function bucketSeconds(): int
    {
        return config("trends.periods.{$this->value}.bucket_seconds");
    }

    /**
     * The device Metrics tab range that best shows this period; it stops at a week.
     */
    public function metricRange(): MetricRange
    {
        return $this === self::Day ? MetricRange::OneDay : MetricRange::OneWeek;
    }

    /**
     * Intl.DateTimeFormat options for the chart's time axis.
     *
     * @return array<string, string|bool>
     */
    public function timeFormat(): array
    {
        return match ($this) {
            self::Day => ['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false],
            self::Week => ['weekday' => 'short', 'hour' => '2-digit', 'hour12' => false],
            self::Month => ['month' => 'short', 'day' => 'numeric'],
        };
    }
}
