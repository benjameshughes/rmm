<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

/**
 * The big numbers across the top of the dashboard.
 */
final readonly class HeroFigures
{
    public function __construct(
        public int $online,
        public int $total,
        public int $offline,
        public int $openAlerts,
        public int $fullDisks,
        public int $failedUnits,
        public AlertTrend $alertTrend,
    ) {}

    /**
     * Share of the fleet online, for the ring.
     */
    public function onlinePercent(): float
    {
        return $this->total === 0 ? 0.0 : round($this->online / $this->total * 100, 1);
    }

    /**
     * A zero problem figure fades back; anything else takes its signal colour.
     */
    public function problemClass(int $value, string $signal = 'red'): string
    {
        return match (true) {
            $value === 0 => 'text-zinc-300 dark:text-zinc-600',
            $signal === 'amber' => 'text-amber-600 dark:text-amber-400',
            default => 'text-red-600 dark:text-red-400',
        };
    }
}
