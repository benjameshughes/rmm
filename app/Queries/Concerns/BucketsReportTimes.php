<?php

declare(strict_types=1);

namespace App\Queries\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BucketsReportTimes
{
    /**
     * Whole buckets since a start time, bound as the first placeholder and the bucket size as
     * the second, worked out from the stored wall-clock values so the connection's time zone never shifts them.
     */
    private function bucketExpression(Builder $query): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'sqlite' => 'CAST(ROUND((julianday(device_metrics.recorded_at) - julianday(?)) * 86400) AS INTEGER) / ?',
            'pgsql' => 'FLOOR(EXTRACT(EPOCH FROM (device_metrics.recorded_at - CAST(? AS timestamp))) / ?)',
            default => 'FLOOR(TIMESTAMPDIFF(SECOND, ?, device_metrics.recorded_at) / ?)',
        };
    }
}
