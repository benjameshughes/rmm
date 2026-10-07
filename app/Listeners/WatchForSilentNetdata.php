<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MetricsReceived;
use App\Events\NetdataWentQuiet;
use Illuminate\Support\Facades\Cache;

/**
 * Counts metrics reports in a row that arrive with no CPU figure. A report with
 * CPU clears the count; reaching the threshold announces it exactly once, so a
 * PC that stays blank is not repaired on every report after.
 */
final class WatchForSilentNetdata
{
    public function handle(MetricsReceived $event): void
    {
        if ($event->device->isMonitorOnly) {
            return;
        }

        $key = config('devices.metrics.blank_reports_cache_key').'.'.$event->device->id;

        if ($event->metric->cpu !== null) {
            Cache::forget($key);

            return;
        }

        Cache::add($key, 0);

        if (Cache::increment($key) === config('devices.metrics.blank_reports_before_repair')) {
            NetdataWentQuiet::dispatch($event->device);
        }
    }
}
