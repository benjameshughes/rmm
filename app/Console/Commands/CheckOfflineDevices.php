<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Alert\EvaluateAlertRules;
use App\Actions\Device\AnnounceDevicesGoneOffline;
use App\Actions\Device\SettleExpiredPowerOn;
use App\Enums\AlertMetric;
use App\Enums\DeviceStatus;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Console\Command;

final class CheckOfflineDevices extends Command
{
    protected $signature = 'devices:check-offline';

    protected $description = 'Check for devices that have gone offline and evaluate alert rules';

    public function handle(EvaluateAlertRules $evaluator, AnnounceDevicesGoneOffline $announceDevicesGoneOffline, SettleExpiredPowerOn $settleExpiredPowerOn): int
    {
        $settleExpiredPowerOn();
        $announceDevicesGoneOffline();

        $offlineRules = AlertRule::query()
            ->active()
            ->where('metric', AlertMetric::Offline)
            ->get();

        if ($offlineRules->isEmpty()) {
            return self::SUCCESS;
        }

        Device::query()
            ->where('status', DeviceStatus::Active)
            ->get()
            ->each(fn (Device $device) => $evaluator($device, new DeviceMetric, $offlineRules));

        return self::SUCCESS;
    }
}
