<?php

declare(strict_types=1);

namespace App\Queries\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BucketsReportTimes
{
    /**
     * Whole buckets since a start time, bound as the first placeholder and the bucket size as
     * the second, worked out from the stored wall-clock values so the connection's time zone never shifts them.
     * $column is a trusted column name, never input.
     */
    private function bucketExpression(Builder $query, string $column = 'device_metrics.recorded_at'): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'sqlite' => "CAST(ROUND((julianday({$column}) - julianday(?)) * 86400) AS INTEGER) / ?",
            'pgsql' => "FLOOR(EXTRACT(EPOCH FROM ({$column} - CAST(? AS timestamp))) / ?)",
            default => "FLOOR(TIMESTAMPDIFF(SECOND, ?, {$column}) / ?)",
        };
    }
}
