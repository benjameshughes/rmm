<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\ServerBackup\SyncServerBackupAlerts;
use App\Events\ServerBackupsReported;
use App\Models\Device;

/**
 * Server backup alerts are raised and resolved as status files are reported,
 * every minute, so an overdue job is noticed as soon as it is due.
 */
final class SyncServerBackupAlertsForReport
{
    public function __construct(private readonly SyncServerBackupAlerts $syncServerBackupAlerts) {}

    public function handle(ServerBackupsReported $event): void
    {
        $device = Device::query()->find($event->deviceId);

        if ($device === null) {
            return;
        }

        ($this->syncServerBackupAlerts)($device);
    }
}
