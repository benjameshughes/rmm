<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the Trends page measures in each period, how it reads and which way is better.
 */
enum TrendMetric: string
{
    case Ram = 'ram';
    case Cpu = 'cpu';
    case Reboots = 'reboots';
    case BlankRate = 'blank_rate';
    case CpuPeak = 'cpu_peak';
    case DiskBusy = 'disk_busy';
    case Swap = 'swap';
    case OnlineHours = 'online_hours';

    public function label(): string
    {
        return match ($this) {
            self::Ram => 'RAM',
            self::Cpu => 'CPU',
            self::Reboots => 'Reboots',
            self::BlankRate => 'Blank reports',
            self::CpuPeak => 'CPU peaks (p95)',
            self::DiskBusy => 'Disk busy',
            self::Swap => 'Page file used',
            self::OnlineHours => 'Hours online',
        };
    }

    /**
     * How the fleet figure is made, for the card's small print.
     */
    public function fleetDescription(): string
    {
        return match ($this) {
            self::Ram, self::Cpu, self::DiskBusy => 'Average use, per PC',
            self::Reboots => 'Uptime resets, all PCs',
            self::BlankRate => 'Reports with no CPU figure',
            self::CpuPeak => 'Each PC\'s 95th percentile',
            self::Swap => 'Average, per PC',
            self::OnlineHours => 'Average, per PC',
        };
    }

    /**
     * Null when neither way is better: more hours online is not progress in itself.
     */
    public function isLowerBetter(): ?bool
    {
        return $this === self::OnlineHours ? null : true;
    }

    /**
     * Counts and rates compare in their own unit; usage compares as a share of what it was.
     */
    public function comparesAbsolutely(): bool
    {
        return in_array($this, [self::Reboots, self::BlankRate], true);
    }

    /**
     * Percentages share one 0-100 scale for the before and after bars.
     */
    public function isPercentage(): bool
    {
        return in_array($this, [self::Ram, self::Cpu, self::CpuPeak, self::DiskBusy, self::BlankRate], true);
    }

    public function steadyWithin(): float
    {
        return (float) config("trends.steady.{$this->value}");
    }

    public function format(?float $value): ?string
    {
        return match (true) {
            $value === null => null,
            $this === self::Reboots => number_format($value),
            $this === self::OnlineHours => number_format($value, 1).' h',
            $this === self::Swap => $value >= 1024 ? number_format($value / 1024, 1).' GB' : number_format($value).' MB',
            $this === self::BlankRate => number_format($value, 1).'%',
            default => number_format($value).'%',
        };
    }

    /**
     * "−10%" for usage, "+3" for reboots, "−1.5 pts" for the blank rate.
     */
    public function differenceForHumans(float $previous, float $current): string
    {
        $difference = $current - $previous;
        $sign = $difference > 0 ? '+' : ($difference < 0 ? '−' : '±');

        return match (true) {
            $this === self::Reboots => $sign.number_format(abs($difference)),
            $this === self::BlankRate => $sign.number_format(abs($difference), 1).' pts',
            $previous == 0.0 => $sign.$this->format(abs($difference)),
            default => $sign.number_format(abs($difference) / $previous * 100).'%',
        };
    }
}
