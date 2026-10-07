<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Models\Device;

/**
 * Turns a PC's backups off. Its repository name and password stay, so its
 * snapshots on scarif can still be opened and enabling it again carries on
 * in the same repository.
 */
final class DisableBackups
{
    public function __construct(
        private readonly SyncBackupAlert $syncBackupAlert,
    ) {}

    public function __invoke(Device $device): void
    {
        $device->forceFill(['backup_configured_at' => null])->save();

        ($this->syncBackupAlert)($device);
    }
}
