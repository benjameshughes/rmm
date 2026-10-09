<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Backup\SyncBackupAlert;
use App\Actions\ServerBackup\SyncServerBackupAlerts;
use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Console\Command;

final class CheckBackups extends Command
{
    protected $signature = 'backups:check';

    protected $description = 'Raise or resolve the backup alerts on every PC with backup credentials and every server reporting backup jobs, so backups that stop are noticed even when nothing reports in';

    public function handle(SyncBackupAlert $syncBackupAlert, SyncServerBackupAlerts $syncServerBackupAlerts): int
    {
        $devices = Device::query()
            ->where('status', DeviceStatus::Active)
            ->withBackupCredentials()
            ->get()
            ->each(fn (Device $device) => $syncBackupAlert($device));

        $servers = Device::query()
            ->where('status', DeviceStatus::Active)
            ->has('serverBackupJobs')
            ->get()
            ->each(fn (Device $device) => $syncServerBackupAlerts($device));

        $this->components->info("Checked backups on {$devices->count()} devices and {$servers->count()} servers.");

        return self::SUCCESS;
    }
}
