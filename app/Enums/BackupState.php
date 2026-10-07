<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a PC's file backups stand, worked out by Device::backupState().
 */
enum BackupState: string
{
    case Healthy = 'healthy';
    case Partial = 'partial';
    case Stale = 'stale';
    case Failed = 'failed';
    case NeverBackedUp = 'never_backed_up';
    case NotConfigured = 'not_configured';

    /**
     * Overdue or failing: what raises the backup alert and shows on the dashboard.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Stale, self::Failed], true);
    }

    /**
     * Worth a badge on the devices table: anything short of healthy on a PC that is set up.
     */
    public function isWorthFlagging(): bool
    {
        return in_array($this, [self::Partial, self::Stale, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Partial => 'Some files skipped',
            self::Stale => 'Overdue',
            self::Failed => 'Failed',
            self::NeverBackedUp => 'No backup yet',
            self::NotConfigured => 'Not set up',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Healthy => 'green',
            self::Partial, self::Stale => 'amber',
            self::Failed => 'red',
            self::NeverBackedUp => 'sky',
            self::NotConfigured => 'zinc',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Healthy => 'shield-check',
            self::Partial => 'exclamation-circle',
            self::Stale => 'clock',
            self::Failed => 'exclamation-triangle',
            self::NeverBackedUp => 'cloud-arrow-up',
            self::NotConfigured => 'minus-circle',
        };
    }
}
