<?php

declare(strict_types=1);

namespace App\Actions\VirtualPrinter;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\VirtualPrinterState;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;

final class SyncVirtualPrinterAlert
{
    /**
     * Raise one alert once a print station has been plainly online without
     * the Virtual Printer for the configured minutes, at any hour, and resolve
     * it when the app is seen again or the PC stops being plainly online.
     * There is no number to compare, so the alert stores placeholders
     * (threshold 0, current_value 1 meaning "down") and its message says how long.
     * The built-in rule is only looked up when an alert is due, so routine
     * reports from PCs that are fine touch nothing.
     *
     * @param  AlertRule|null  $rule  Defaults to the built-in Virtual Printer rule
     */
    public function __invoke(Device $device, ?AlertRule $rule = null): void
    {
        $openAlerts = $device->unresolvedAlerts()->where('metric', AlertMetric::VirtualPrinterDown)->get();

        if (! $this->isAlertDue($device)) {
            $openAlerts->each(fn (Alert $alert) => $alert->resolve());

            return;
        }

        $message = "{$device->hostname}: ".config('devices.watched_apps.virtual_printer.label')." not running for {$device->virtualPrinterDownForHumans()}";

        if ($openAlerts->isNotEmpty()) {
            $openAlerts->each(fn (Alert $alert) => $alert->update(['message' => $message]));

            return;
        }

        $rule ??= AlertRule::virtualPrinterDown();

        if (! $rule->is_active) {
            return;
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::VirtualPrinterDown,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]);
    }

    private function isAlertDue(Device $device): bool
    {
        return $device->virtualPrinterState() === VirtualPrinterState::Down
            && $device->hasVirtualPrinterBeenMissingFor(minutes: config('devices.watched_apps.virtual_printer.alert_after_minutes'));
    }
}
