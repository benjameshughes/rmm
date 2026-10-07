<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Backup\SyncBackupAlert;
use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Console\Command;

final class CheckBackups extends Command
{
    protected $signature = 'backups:check';

    protected $description = 'Raise or resolve the backup alert on every PC with backup credentials, so a PC that stops backing up is noticed even when no run reports in';

    public function handle(SyncBackupAlert $syncBackupAlert): int
    {
        $devices = Device::query()
            ->where('status', DeviceStatus::Active)
            ->withBackupCredentials()
            ->get()
            ->each(fn (Device $device) => $syncBackupAlert($device));

        $this->components->info("Checked backups on {$devices->count()} devices.");

        return self::SUCCESS;
    }
}
