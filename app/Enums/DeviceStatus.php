<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';

    public function isApproved(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Revoked => 'zinc',
        };
    }

    public function canResetEnrolment(): bool
    {
        return $this !== self::Pending;
    }

    public function agentStatus(): string
    {
        return match ($this) {
            self::Active => 'approved',
            default => $this->value,
        };
    }
}
