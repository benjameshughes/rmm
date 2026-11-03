<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How urgently a device on the dashboard needs looking at. Info is worth knowing
 * but never breaks the all-clear.
 */
enum AttentionLevel: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }

    public function isActionable(): bool
    {
        return $this !== self::Info;
    }

    public function iconColor(): string
    {
        return match ($this) {
            self::Critical => 'text-red-500',
            self::Warning => 'text-amber-500',
            self::Info => 'text-sky-500',
        };
    }

    /**
     * The small square that leads a dashboard attention row.
     */
    public function squareClass(): string
    {
        return match ($this) {
            self::Critical => 'bg-red-500',
            self::Warning => 'bg-amber-500',
            self::Info => 'bg-zinc-400 dark:bg-zinc-500',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Info => 'information-circle',
            default => 'exclamation-triangle',
        };
    }
}
