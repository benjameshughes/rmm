<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;

final class SyncAgentOutdatedAlert
{
    /**
     * Raise one alert while the device's agent is behind the latest release and
     * resolve it once the agent catches up. Versions are not numbers, so the
     * alert stores placeholders (threshold 0, current_value 1 meaning "outdated")
     * and its message carries the versions.
     *
     * @param  AlertRule|null  $rule  Defaults to the built-in outdated-agent rule
     */
    public function __invoke(Device $device, ?string $latestVersion, ?AlertRule $rule = null): void
    {
        if ($device->status !== DeviceStatus::Active || $device->agent_version === null || $latestVersion === null) {
            return;
        }

        $rule ??= AlertRule::agentOutdated();
        $openAlerts = $device->unresolvedAlerts->where('alert_rule_id', $rule->id);

        if (! $device->isAgentOutdated($latestVersion)) {
            $openAlerts->each(fn (Alert $alert) => $alert->resolve());

            return;
        }

        $message = "{$device->hostname} is on agent {$device->agent_version}, latest is {$latestVersion}";

        if ($openAlerts->isNotEmpty()) {
            $openAlerts->each(fn (Alert $alert) => $alert->update(['message' => $message]));

            return;
        }

        if (! $rule->is_active) {
            return;
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::AgentOutdated,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]);
    }
}
