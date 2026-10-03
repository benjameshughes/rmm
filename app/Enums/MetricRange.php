<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

enum MetricRange: string
{
    case OneHour = '1h';
    case OneDay = '24h';
    case OneWeek = '7d';

    public function label(): string
    {
        return match ($this) {
            self::OneHour => 'Last hour',
            self::OneDay => 'Last 24 hours',
            self::OneWeek => 'Last 7 days',
        };
    }

    /**
     * Intl.DateTimeFormat options for the time axis: a week needs the day, an hour does not.
     *
     * @return array<string, string|bool>
     */
    public function timeFormat(): array
    {
        return match ($this) {
            self::OneWeek => ['weekday' => 'short', 'hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false],
            default => ['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false],
        };
    }

    public function startsAt(): Carbon
    {
        return now()->subMinutes(config("devices.metrics.chart_ranges.{$this->value}.minutes"));
    }

    public function bucketSeconds(): int
    {
        return config("devices.metrics.chart_ranges.{$this->value}.bucket_seconds");
    }
}
