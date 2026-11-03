<?php

declare(strict_types=1);

namespace App\Enums;

enum TrendDirection: string
{
    case Improved = 'improved';
    case Worse = 'worse';
    case Steady = 'steady';
    case Changed = 'changed';
    case Unmeasured = 'unmeasured';

    public function label(): string
    {
        return match ($this) {
            self::Improved => 'Better',
            self::Worse => 'Worse',
            self::Steady => 'No real change',
            self::Changed => 'Changed',
            self::Unmeasured => 'Not enough data',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Improved => 'green',
            self::Worse => 'red',
            default => 'zinc',
        };
    }

    /**
     * Small delta text: only a turn for the worse is coloured.
     */
    public function textClass(): string
    {
        return match ($this) {
            self::Worse => 'text-red-600 dark:text-red-400',
            default => 'text-zinc-400 dark:text-zinc-500',
        };
    }
}
