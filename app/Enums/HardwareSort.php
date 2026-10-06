<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The hardware page's sortable columns. PC is the default so rows never move on their own.
 */
enum HardwareSort: string
{
    case Hostname = 'pc';
    case Model = 'model';
    case Ram = 'ram';
    case Collected = 'collected';

    public function label(): string
    {
        return match ($this) {
            self::Hostname => 'PC',
            self::Model => 'Model',
            self::Ram => 'RAM',
            self::Collected => 'Collected',
        };
    }

    /**
     * Sizes and dates read biggest and newest first, names A to Z.
     */
    public function defaultDirection(): string
    {
        return match ($this) {
            self::Ram, self::Collected => 'desc',
            default => 'asc',
        };
    }
}
