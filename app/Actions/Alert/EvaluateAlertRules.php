<?php

declare(strict_types=1);

namespace App\Actions\Alert;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EvaluateAlertRules
{
    /** @param Collection<int, AlertRule>|null $rules Defaults to every active rule */
    public function __invoke(Device $device, DeviceMetric $metric, ?Collection $rules = null): void
    {
        ($rules ?? AlertRule::query()->active()->get())
            ->each(fn (AlertRule $rule) => $this->evaluateRule($rule, $device, $metric));
    }

    private function evaluateRule(AlertRule $rule, Device $device, DeviceMetric $metric): void
    {
        $currentValue = $this->getCurrentValue($rule->metric, $device, $metric);

        if ($currentValue === null) {
            return;
        }

        $isViolating = $rule->operator->evaluate($currentValue, $rule->threshold);

        if ($isViolating) {
            $this->handleViolation($rule, $device, $currentValue);
        } else {
            $this->handleRecovery($rule, $device);
        }
    }

    private function getCurrentValue(AlertMetric $metric, Device $device, DeviceMetric $deviceMetric): ?float
    {
        return match ($metric) {
            AlertMetric::Cpu => $deviceMetric->cpu,
            AlertMetric::Ram => $deviceMetric->ram,
            AlertMetric::Disk => $this->getMaxDiskUsage($deviceMetric),
            AlertMetric::CpuQueue => $deviceMetric->cpu_queue_length,
            AlertMetric::DiskBusy => $deviceMetric->disk_busy_percent,
            AlertMetric::PageFile => $deviceMetric->pageFilePercent(),
            AlertMetric::Offline => $this->getOfflineMinutes($device),
            AlertMetric::AgentOutdated, AlertMetric::ScriptFailed, AlertMetric::VirtualPrinterDown, AlertMetric::PrinterProblem, AlertMetric::SpoolerDown, AlertMetric::BackupOverdue => null,
        };
    }

    private function getMaxDiskUsage(DeviceMetric $metric): ?float
    {
        $maxUsage = $metric->diskMetrics->max('usage_percent');

        return $maxUsage !== null ? (float) $maxUsage : null;
    }

    /**
     * A device that announced it was sleeping or shutting down is off on purpose, so it is not evaluated
     * at all until that notice lapses.
     */
    private function getOfflineMinutes(Device $device): ?float
    {
        if ($device->isPoweringOff) {
            return null;
        }

        if ($device->last_seen === null) {
            return 9999.0;
        }

        return (float) abs(now()->diffInMinutes($device->last_seen));
    }

    private function handleViolation(AlertRule $rule, Device $device, float $currentValue): void
    {
        $existingAlert = Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('device_id', $device->id)
            ->unresolved()
            ->first();

        if ($existingAlert !== null) {
            $existingAlert->update(['current_value' => $currentValue]);

            return;
        }

        if ($rule->metric !== AlertMetric::Offline) {
            $violationStart = $this->findViolationStart($rule, $device);
            if ($violationStart === null || abs(now()->diffInMinutes($violationStart)) < $rule->duration_minutes) {
                return;
            }
        }

        Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => $rule->metric,
            'threshold' => $rule->threshold,
            'current_value' => $currentValue,
            'message' => "{$device->hostname}: {$rule->metric->label()} is {$currentValue} (threshold: {$rule->threshold})",
            'triggered_at' => now(),
        ]);
    }

    private function handleRecovery(AlertRule $rule, Device $device): void
    {
        Alert::query()
            ->where('alert_rule_id', $rule->id)
            ->where('device_id', $device->id)
            ->unresolved()
            ->get()
            ->each(fn (Alert $alert) => $alert->resolve());
    }

    /**
     * Walk back from the newest metric to find when the current unbroken run of violations began.
     */
    private function findViolationStart(AlertRule $rule, Device $device): ?Carbon
    {
        $windowMetrics = $device->metrics()
            ->select(['id', 'device_id', 'cpu', 'ram', 'cpu_queue_length', 'disk_busy_percent', 'swap_used_mib', 'swap_total_mib', 'recorded_at'])
            ->when($rule->metric === AlertMetric::Disk, fn ($query) => $query->with('diskMetrics:id,device_metric_id,usage_percent'))
            ->where('recorded_at', '>=', now()->subMinutes($rule->duration_minutes + 5))
            ->latest('recorded_at')
            ->get();

        return $windowMetrics
            ->takeWhile(fn (DeviceMetric $metric): bool => $this->isViolating($rule, $device, $metric))
            ->last()
            ?->recorded_at;
    }

    private function isViolating(AlertRule $rule, Device $device, DeviceMetric $metric): bool
    {
        $value = $this->getCurrentValue($rule->metric, $device, $metric);

        return $value !== null && $rule->operator->evaluate($value, $rule->threshold);
    }
}
