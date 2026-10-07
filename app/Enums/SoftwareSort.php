<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fleet software list's sortable columns. Behind is the default: the
 * packages with devices to upgrade come first.
 */
enum SoftwareSort: string
{
    case Name = 'name';
    case Devices = 'devices';
    case Versions = 'versions';
    case Behind = 'behind';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'App',
            self::Devices => 'Installed on',
            self::Versions => 'Versions',
            self::Behind => 'Behind',
        };
    }

    /**
     * The aggregate column fleetPackages selects for it.
     */
    public function column(): string
    {
        return match ($this) {
            self::Name => 'name',
            self::Devices => 'device_count',
            self::Versions => 'version_count',
            self::Behind => 'outdated_count',
        };
    }

    /**
     * Names read A to Z, counts biggest first.
     */
    public function defaultDirection(): string
    {
        return match ($this) {
            self::Name => 'asc',
            default => 'desc',
        };
    }
}
