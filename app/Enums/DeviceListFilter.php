<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The slices of the fleet the device list's summary cards filter to.
 */
enum DeviceListFilter: string
{
    case Online = 'online';
    case PoweringOff = 'powering-off';
    case Offline = 'offline';
    case Outdated = 'outdated';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::PoweringOff => 'Powering off',
            self::Offline => 'Offline',
            self::Outdated => 'Agent behind',
        };
    }
}
