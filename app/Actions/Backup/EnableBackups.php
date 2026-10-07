<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Models\Device;
use Illuminate\Support\Str;

/**
 * Turns a PC's backups on. The first time it names the PC's repository
 * after its lowercase hostname and generates a long random repository
 * password, letters and digits only, stored encrypted and audited by name
 * only. Both are kept for good after that: a renamed PC keeps its
 * repository, and the repository is encrypted with that password, so a new
 * one would lock the PC out of its own backups.
 */
final class EnableBackups
{
    public function __construct(
        private readonly SyncBackupAlert $syncBackupAlert,
    ) {}

    public function __invoke(Device $device): void
    {
        $device->forceFill([
            'backup_repository_name' => $device->backup_repository_name ?? Str::lower($device->hostname),
            'backup_repository_password' => $device->backup_repository_password ?? Str::password(length: config('backup.generated_password_length'), symbols: false),
            'backup_configured_at' => $device->backup_configured_at ?? now(),
        ])->save();

        ($this->syncBackupAlert)($device);
    }
}
