<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduleTargetType: string
{
    case All = 'all';
    case Group = 'group';
    case Tag = 'tag';
    case Device = 'device';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All Devices',
            self::Group => 'Group',
            self::Tag => 'Tag',
            self::Device => 'Single Device',
        };
    }
}
