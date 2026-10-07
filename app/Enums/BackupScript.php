<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The backup system scripts, each value its slug.
 */
enum BackupScript: string
{
    case BackUp = 'backup-files';
    case ListSnapshots = 'backup-snapshots';
    case Restore = 'backup-restore';
    case InstallRestic = 'install-restic';

    public function queuedHeading(): string
    {
        return match ($this) {
            self::BackUp => 'Backup queued',
            self::ListSnapshots => 'Refreshing snapshots',
            self::Restore => 'Restore queued',
            self::InstallRestic => 'Installing restic',
        };
    }
}
