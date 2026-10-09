<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens to a deleted path: gone for good, or moved aside for a few days first.
 */
enum DeleteMode: string
{
    case Delete = 'delete';
    case Quarantine = 'quarantine';

    public function label(): string
    {
        return match ($this) {
            self::Delete => 'Delete permanently',
            self::Quarantine => 'Quarantine',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Delete => 'Frees the space now. There is no undo.',
            self::Quarantine => 'Moves it aside on the system drive, restorable for '.config('devices.delete_path.quarantine_days').' days. The space only comes back once it is purged.',
        };
    }

    /**
     * The start of the toast once it is queued: "Delete queued: Veeam Backup Cache".
     */
    public function queuedLabel(): string
    {
        return match ($this) {
            self::Delete => 'Delete queued:',
            self::Quarantine => 'Quarantine queued:',
        };
    }

    /**
     * The confirm button: "Permanently delete 103.7 GB from OFFICE-PC".
     */
    public function confirmLabel(string $what, string $hostname): string
    {
        return match ($this) {
            self::Delete => "Permanently delete {$what} from {$hostname}",
            self::Quarantine => "Quarantine {$what} on {$hostname}",
        };
    }

    public function auditAction(): AuditAction
    {
        return match ($this) {
            self::Delete => AuditAction::PathDeleted,
            self::Quarantine => AuditAction::PathQuarantined,
        };
    }
}
