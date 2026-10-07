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

    public function barColor(): string
    {
        return match ($this) {
            self::Improved => 'bg-green-500',
            self::Worse => 'bg-red-500',
            default => 'bg-zinc-500 dark:bg-zinc-400',
        };
    }
}
