<?php

declare(strict_types=1);

namespace App\Actions\Netdata;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\CommandStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\DeviceCommand;
use Illuminate\Support\Str;

final class SyncNetdataRepairAlert
{
    /**
     * Raise one alert per device when the Netdata repair script fails or times
     * out, since the next step (a reboot) is left to a person, and resolve it
     * when a later repair works. A report with CPU resolves it too, from the
     * metrics side. A second failure only refreshes the open alert's message,
     * and the repair cooldown keeps failures to one a day. There is no number
     * to compare, so the alert stores placeholders (threshold 0, current_value 1).
     *
     * @param  AlertRule|null  $rule  Defaults to the built-in Netdata repair rule
     */
    public function __invoke(DeviceCommand $repair, ?AlertRule $rule = null): void
    {
        if (! $repair->status->isTerminal() || $repair->status === CommandStatus::Cancelled) {
            return;
        }

        $openAlerts = $repair->device->unresolvedAlerts()->where('metric', AlertMetric::NetdataRepairFailed)->get();

        if ($repair->isSuccessful()) {
            $openAlerts->each(fn (Alert $alert) => $alert->resolve());

            return;
        }

        $message = $this->messageFor($repair);

        if ($openAlerts->isNotEmpty()) {
            $openAlerts->each(fn (Alert $alert) => $alert->update(['message' => $message]));

            return;
        }

        $rule ??= AlertRule::netdataRepairFailed();

        if (! $rule->is_active) {
            return;
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $repair->device_id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::NetdataRepairFailed,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]);
    }

    private function messageFor(DeviceCommand $repair): string
    {
        $attention = Str::of($repair->stdout())
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->last(fn (string $line): bool => Str::startsWith($line, 'ATTENTION:'));

        return str("{$repair->device->hostname}: Netdata repair failed, metrics are blank. Consider rebooting the PC.")
            ->when($attention !== null, fn ($message) => $message->append(' ', Str::limit($attention, config('devices.metrics.netdata_repair_alert.attention_max_length'))))
            ->toString();
    }
}
