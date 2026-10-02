<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\CommandStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DeviceCommand;

final class SyncScheduledScriptAlert
{
    /**
     * Raise one alert per device and schedule while its scheduled script keeps
     * failing, and resolve it on the next clean run. Scheduled scripts exit 0
     * when healthy and non-zero when something needs attention, so the alert
     * stores placeholders (threshold 0, current_value 1 meaning "failed") and
     * its message carries the script's summary line.
     *
     * @param  AlertRule|null  $rule  Defaults to the built-in scheduled-script rule
     */
    public function __invoke(DeviceCommand $command, ?AlertRule $rule = null): void
    {
        if ($command->scheduledTask === null || ! $command->status->isTerminal() || $command->status === CommandStatus::Cancelled) {
            return;
        }

        $rule ??= AlertRule::scheduledScriptFailed();
        $openAlerts = Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('device_id', $command->device_id)
            ->where('scheduled_task_id', $command->scheduled_task_id)
            ->unresolved()
            ->get();

        if ($command->isSuccessful()) {
            $openAlerts->each(fn (Alert $alert) => $alert->resolve());

            return;
        }

        $message = "{$command->device->hostname}: {$command->scheduledTask->name}: {$command->summaryLine()}";

        if ($openAlerts->isNotEmpty()) {
            $openAlerts->each(fn (Alert $alert) => $alert->update(['message' => $message]));

            return;
        }

        if (! $rule->is_active) {
            return;
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $command->device_id,
            'scheduled_task_id' => $command->scheduled_task_id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::ScriptFailed,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]);
    }
}
