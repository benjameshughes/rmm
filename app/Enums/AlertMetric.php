<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertMetric: string
{
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Cpu => 'CPU Usage',
            self::Ram => 'RAM Usage',
            self::Disk => 'Disk Usage',
            self::Offline => 'Device Offline',
        };
    }
}
