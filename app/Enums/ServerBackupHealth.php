<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one server backup job stands, worked out by ServerBackupJob::health()
 * from its last status file. See `servers` in config/backup.php for the rules.
 */
enum ServerBackupHealth: string
{
    case Healthy = 'healthy';
    case Overdue = 'overdue';
    case Failed = 'failed';
    case Shrunk = 'shrunk';
    case Unreadable = 'unreadable';
    case Missing = 'missing';

    public function needsAttention(): bool
    {
        return $this !== self::Healthy;
    }

    /**
     * Worst first, for lists that put red rows at the top.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Failed, self::Shrunk => 0,
            self::Overdue, self::Unreadable, self::Missing => 1,
            self::Healthy => 2,
        };
    }

    public function calloutVariant(): string
    {
        return $this->rank() === 0 ? 'danger' : 'warning';
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Overdue => 'Overdue',
            self::Failed => 'Failed',
            self::Shrunk => 'Shrunk',
            self::Unreadable => 'Status file unreadable',
            self::Missing => 'Status file missing',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'green',
            self::Overdue, self::Unreadable, self::Missing => 'amber',
            self::Failed, self::Shrunk => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Healthy => 'shield-check',
            self::Overdue => 'clock',
            self::Failed => 'exclamation-triangle',
            self::Shrunk => 'arrow-trending-down',
            self::Unreadable => 'document-magnifying-glass',
            self::Missing => 'eye-slash',
        };
    }
}
