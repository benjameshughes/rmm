<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;

final class SyncBackupAlert
{
    /**
     * Raise one alert while a PC with backup credentials is overdue or its
     * last run failed, whether or not it is online: a PC that has been off
     * still has an old backup. It resolves once a good backup lands. There is
     * no number to compare, so the alert stores placeholders (threshold 0,
     * current_value 1) and its message says why. The built-in rule is only
     * looked up when a new alert is due.
     *
     * @param  AlertRule|null  $rule  Defaults to the built-in backup rule
     */
    public function __invoke(Device $device, ?AlertRule $rule = null): void
    {
        $openAlerts = $device->unresolvedAlerts()->where('metric', AlertMetric::BackupOverdue)->get();
        $problem = $device->backupProblem();

        if ($problem === null) {
            $openAlerts->each(fn (Alert $alert) => $alert->resolve());

            return;
        }

        $message = "{$device->hostname}: {$problem}";

        if ($openAlerts->isNotEmpty()) {
            $openAlerts->each(fn (Alert $alert) => $alert->update(['message' => $message]));

            return;
        }

        $rule ??= AlertRule::backupOverdue();

        if (! $rule->is_active) {
            return;
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::BackupOverdue,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]);
    }
}
