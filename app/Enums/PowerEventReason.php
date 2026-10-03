<?php

declare(strict_types=1);

namespace App\Enums;

enum PowerEventReason: string
{
    case Sleep = 'sleep';
    case Standby = 'standby';
    case Shutdown = 'shutdown';
    case Resume = 'resume';
    case Boot = 'boot';

    public function powerState(): DevicePowerState
    {
        return match ($this) {
            self::Sleep, self::Standby, self::Shutdown => DevicePowerState::PoweringOff,
            self::Resume, self::Boot => DevicePowerState::PoweringOn,
        };
    }
}
