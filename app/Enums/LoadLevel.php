<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How hard a CPU or RAM figure is working, against devices.load thresholds.
 */
enum LoadLevel: string
{
    case Normal = 'normal';
    case Busy = 'busy';
    case Maxed = 'maxed';

    public static function fromPercent(?float $percent): self
    {
        return match (true) {
            $percent > config('devices.load.critical_percent') => self::Maxed,
            $percent > config('devices.load.warning_percent') => self::Busy,
            default => self::Normal,
        };
    }

    /**
     * Only past the warning line does a figure leave plain ink; the dashboard keeps it amber either way.
     */
    public function textClass(): string
    {
        return match ($this) {
            self::Normal => 'text-zinc-800 dark:text-zinc-100',
            self::Busy, self::Maxed => 'text-amber-600 dark:text-amber-400',
        };
    }
}
