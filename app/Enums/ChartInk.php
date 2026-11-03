<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stroke colours for the dashboard's ring charts: greys for calm data and current values,
 * and the signal colours for online, warning and critical.
 */
enum ChartInk: string
{
    case Faint = 'faint';
    case Light = 'light';
    case Mid = 'mid';
    case Ink = 'ink';
    case Online = 'online';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * The greys for calm bands, palest first.
     *
     * @return array<int, self>
     */
    public static function calmBands(): array
    {
        return [self::Faint, self::Light, self::Mid];
    }

    public function strokeClass(): string
    {
        return match ($this) {
            self::Faint => 'stroke-zinc-200 dark:stroke-zinc-700',
            self::Light => 'stroke-zinc-300 dark:stroke-zinc-600',
            self::Mid => 'stroke-zinc-400 dark:stroke-zinc-500',
            self::Ink => 'stroke-zinc-800 dark:stroke-zinc-100',
            self::Online => 'stroke-green-500',
            self::Warning => 'stroke-amber-500',
            self::Critical => 'stroke-red-500',
        };
    }

    /**
     * The same ink as a filled segment, for the hero meters.
     */
    public function fillClass(): string
    {
        return match ($this) {
            self::Faint => 'bg-zinc-200 dark:bg-zinc-700',
            self::Light => 'bg-zinc-300 dark:bg-zinc-600',
            self::Mid => 'bg-zinc-400 dark:bg-zinc-500',
            self::Ink => 'bg-zinc-800 dark:bg-zinc-100',
            self::Online => 'bg-green-500',
            self::Warning => 'bg-amber-500',
            self::Critical => 'bg-red-500',
        };
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Warning, self::Critical], true);
    }
}
