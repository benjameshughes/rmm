<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DevicePrinter;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

final class SyncPrinterAlerts
{
    /**
     * Raise one alert per printer that has been a problem for the configured
     * minutes on a plainly online PC, and one for a print spooler down that
     * long, at any hour. Each resolves once its problem clears or the PC is no
     * longer watched. There is no number to compare, so alerts store
     * placeholders (threshold 0, current_value 1) and the message says why.
     * The built-in rules are only looked up when a new alert is due.
     */
    public function __invoke(Device $device): void
    {
        $openAlerts = $device->unresolvedAlerts()
            ->whereIn('metric', [AlertMetric::PrinterProblem, AlertMetric::SpoolerDown])
            ->get()
            ->groupBy(fn (Alert $alert): string => $alert->metric->value);

        $this->sync($device, AlertMetric::PrinterProblem, $openAlerts->get(AlertMetric::PrinterProblem->value, collect()), $this->duePrinterProblems($device), AlertRule::printerProblem(...));
        $this->sync($device, AlertMetric::SpoolerDown, $openAlerts->get(AlertMetric::SpoolerDown->value, collect()), $this->dueSpoolerDown($device), AlertRule::spoolerDown(...));
    }

    /**
     * @return Collection<string, string> Messages keyed by printer name
     */
    private function duePrinterProblems(Device $device): Collection
    {
        if (! $device->isWatchingPrinters) {
            return collect();
        }

        $device->load('problemPrinters');

        return $device->currentPrinterProblems()
            ->filter(fn (DevicePrinter $printer): bool => $printer->problem_since->lessThanOrEqualTo($this->dueBefore()))
            ->mapWithKeys(fn (DevicePrinter $printer): array => [$printer->name => "{$device->hostname}: {$printer->problemLabel()}"]);
    }

    /**
     * @return Collection<string, string>
     */
    private function dueSpoolerDown(Device $device): Collection
    {
        return $device->isSpoolerDown && $device->spooler_down_since->lessThanOrEqualTo($this->dueBefore())
            ? collect([config('printers.alerts.spooler_down.rule_name') => "{$device->hostname}: print spooler not running for {$device->spooler_down_since->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true)}"])
            : collect();
    }

    /**
     * @param  Collection<int, Alert>  $openAlerts
     * @param  Collection<string, string>  $due  Messages keyed by subject
     * @param  Closure(): AlertRule  $builtInRule
     */
    private function sync(Device $device, AlertMetric $metric, Collection $openAlerts, Collection $due, Closure $builtInRule): void
    {
        $openAlerts->each(fn (Alert $alert) => $due->has($alert->subject)
            ? $alert->update(['message' => $due->get($alert->subject)])
            : $alert->resolve());

        $newAlerts = $due->reject(fn (string $message, string $subject): bool => $openAlerts->contains('subject', $subject));

        if ($newAlerts->isEmpty()) {
            return;
        }

        $rule = $builtInRule();

        if (! $rule->is_active) {
            return;
        }

        $newAlerts->each(fn (string $message, string $subject): Alert => Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => $metric,
            'subject' => $subject,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]));
    }

    private function dueBefore(): CarbonInterface
    {
        return now()->subMinutes(config('printers.alerts.after_minutes'));
    }
}
