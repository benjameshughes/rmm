<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The device list's sortable columns. Hostname is the default so rows never move on their own.
 */
enum DeviceListSort: string
{
    case Hostname = 'hostname';
    case Status = 'status';
    case Group = 'group';
    case Cpu = 'cpu';
    case Ram = 'ram';
    case Disk = 'disk';
    case Agent = 'agent';
    case LastSeen = 'last-seen';

    public function label(): string
    {
        return match ($this) {
            self::Hostname => 'Device',
            self::Status => 'Status',
            self::Group => 'Group',
            self::Cpu => 'CPU',
            self::Ram => 'RAM',
            self::Disk => 'Disk',
            self::Agent => 'Agent',
            self::LastSeen => 'Last seen',
        };
    }

    /**
     * Live figures read best busiest first, names and versions A to Z.
     */
    public function defaultDirection(): string
    {
        return match ($this) {
            self::Cpu, self::Ram, self::Disk, self::LastSeen => 'desc',
            default => 'asc',
        };
    }
}
