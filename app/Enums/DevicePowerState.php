<?php

declare(strict_types=1);

namespace App\Enums;

enum DevicePowerState: string
{
    case PoweringOff = 'powering_off';
    case PoweringOn = 'powering_on';

    public function label(): string
    {
        return match ($this) {
            self::PoweringOff => 'Powering off',
            self::PoweringOn => 'Powering on',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PoweringOff => 'amber',
            self::PoweringOn => 'sky',
        };
    }

    /** @return array<int, PowerEventReason> */
    public function reasons(): array
    {
        return collect(PowerEventReason::cases())
            ->filter(fn (PowerEventReason $reason): bool => $reason->powerState() === $this)
            ->values()
            ->all();
    }
}
