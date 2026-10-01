<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertStatus: string
{
    case Triggered = 'triggered';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function color(): string
    {
        return match ($this) {
            self::Triggered => 'red',
            self::Acknowledged => 'amber',
            self::Resolved => 'green',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Triggered => 'Triggered',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }
}
