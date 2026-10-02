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

    /**
     * What moving an alert into this status records in the audit log; alerts only enter Triggered when they are raised.
     */
    public function auditAction(): ?AuditAction
    {
        return match ($this) {
            self::Triggered => null,
            self::Acknowledged => AuditAction::AlertAcknowledged,
            self::Resolved => AuditAction::AlertResolved,
        };
    }
}
