<?php

declare(strict_types=1);

namespace App\Actions\ServerBackup;

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\ServerBackupJob;
use Illuminate\Support\Collection;

final class SyncServerBackupAlerts
{
    /**
     * Raise one alert per server backup job that is not healthy, with the
     * job as its subject, and resolve it once the job is healthy again or
     * forgotten. There is no number to compare, so alerts store placeholders
     * (threshold 0, current_value 1) and the message says why. The built-in
     * rule is only looked up when a new alert is due.
     */
    public function __invoke(Device $device): void
    {
        $openAlerts = $device->unresolvedAlerts()->where('metric', AlertMetric::ServerBackupProblem)->get();

        $due = $device->serverBackupJobs()->get()
            ->mapWithKeys(fn (ServerBackupJob $job): array => [$job->job => $job->problem()])
            ->filter()
            ->map(fn (string $problem, int|string $job): string => "{$device->hostname} {$job} backup: {$problem}");

        $openAlerts->each(fn (Alert $alert) => $due->has($alert->subject)
            ? $alert->update(['message' => $due->get($alert->subject)])
            : $alert->resolve());

        $this->raise($device, $due->reject(fn (string $message, int|string $job): bool => $openAlerts->contains('subject', $job)));
    }

    /**
     * @param  Collection<string, string>  $newAlerts  Messages keyed by job
     */
    private function raise(Device $device, Collection $newAlerts): void
    {
        if ($newAlerts->isEmpty()) {
            return;
        }

        $rule = AlertRule::serverBackupProblem();

        if (! $rule->is_active) {
            return;
        }

        $newAlerts->each(fn (string $message, int|string $job): Alert => Alert::create([
            'alert_rule_id' => $rule->id,
            'device_id' => $device->id,
            'status' => AlertStatus::Triggered,
            'severity' => $rule->severity,
            'metric' => AlertMetric::ServerBackupProblem,
            'subject' => (string) $job,
            'threshold' => 0,
            'current_value' => 1,
            'message' => $message,
            'triggered_at' => now(),
        ]));
    }
}
