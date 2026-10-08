<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AlertMetric;
use App\Events\MetricsReceived;
use App\Models\Alert;

/**
 * A report with CPU proves Netdata is working again, so a failed repair's
 * alert is no longer true.
 */
final class ResolveNetdataRepairAlertForMetrics
{
    public function handle(MetricsReceived $event): void
    {
        if ($event->metric->cpu === null) {
            return;
        }

        $event->device->unresolvedAlerts()
            ->where('metric', AlertMetric::NetdataRepairFailed)
            ->get()
            ->each(fn (Alert $alert) => $alert->resolve());
    }
}
