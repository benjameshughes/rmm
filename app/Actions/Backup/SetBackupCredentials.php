<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Models\Device;

/**
 * Sets a PC's backup credentials: its rest-server user and password and its
 * repository password, stored encrypted and audited by name only. A blank
 * password keeps the one already set, so the username can change alone.
 */
final class SetBackupCredentials
{
    public function __construct(
        private readonly SyncBackupAlert $syncBackupAlert,
    ) {}

    public function __invoke(Device $device, string $restUsername, ?string $restPassword, ?string $repositoryPassword): void
    {
        $device->forceFill(array_filter([
            'backup_rest_username' => $restUsername,
            'backup_rest_password' => $restPassword,
            'backup_repository_password' => $repositoryPassword,
            'backup_configured_at' => $device->backup_configured_at ?? now(),
        ], fn (mixed $value): bool => $value !== null && $value !== ''))->save();

        ($this->syncBackupAlert)($device);
    }
}
