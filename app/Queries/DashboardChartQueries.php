<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Dashboard\AlertTrend;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\DeviceMetric;
use App\Queries\Concerns\BucketsReportTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The small time series the dashboard draws: alerts per hour and each busy device's recent CPU,
 * bucketed by the database so a chart gets a couple of dozen points, never thousands of rows.
 */
final class DashboardChartQueries
{
    use BucketsReportTimes;

    /**
     * Alerts raised in the latest window against the window before it, counted in one query.
     */
    public function alertTrend(): AlertTrend
    {
        $windowHours = config('dashboard.alert_window_hours');
        $now = CarbonImmutable::now();
        $latestStartsAt = $now->subHours($windowHours);

        $counts = Alert::query()
            ->toBase()
            ->selectRaw('SUM(CASE WHEN triggered_at >= ? THEN 1 ELSE 0 END) as latest', [$latestStartsAt->format('Y-m-d H:i:s')])
            ->selectRaw('SUM(CASE WHEN triggered_at < ? THEN 1 ELSE 0 END) as previous', [$latestStartsAt->format('Y-m-d H:i:s')])
            ->where('triggered_at', '>=', $latestStartsAt->subHours($windowHours))
            ->where('triggered_at', '<=', $now)
            ->first();

        return new AlertTrend(latest: (int) $counts->latest, previous: (int) $counts->previous);
    }

    /**
     * Alerts raised per bucket over the latest window, split by severity, oldest bucket first.
     *
     * @return Collection<int, array{time: string, warning: int, critical: int, total: int}>
     */
    public function alertsPerBucket(): Collection
    {
        $bucketSeconds = config('dashboard.alert_bucket_seconds');
        $bucketCount = intdiv(config('dashboard.alert_window_hours') * 3600, $bucketSeconds);
        $startsAt = CarbonImmutable::now()->subSeconds($bucketCount * $bucketSeconds);
        $query = Alert::query();

        $buckets = $query
            ->selectRaw($this->bucketExpression($query, 'alerts.triggered_at').' as bucket', [$startsAt->format('Y-m-d H:i:s'), $bucketSeconds])
            ->selectRaw('severity, COUNT(*) as total')
            ->where('triggered_at', '>=', $startsAt)
            ->groupBy('bucket', 'severity')
            ->toBase()
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->bucket);

        return collect(range(0, $bucketCount - 1))->map(function (int $bucket) use ($buckets, $startsAt, $bucketSeconds): array {
            $rows = $buckets->get($bucket, collect());
            $warning = (int) $rows->where('severity', AlertSeverity::Warning->value)->sum('total');
            $critical = (int) $rows->where('severity', AlertSeverity::Critical->value)->sum('total');

            return [
                'time' => $startsAt->addSeconds($bucket * $bucketSeconds)->toMutable()->toChartTime(),
                'warning' => $warning,
                'critical' => $critical,
                'total' => $warning + $critical,
            ];
        });
    }

    /**
     * Average CPU per bucket for each of the given devices over the sparkline window, in one query.
     *
     * @param  array<int, int>  $deviceIds
     * @return Collection<int, Collection<int, array{time: string, cpu: ?float}>> keyed by device id
     */
    public function cpuSparklines(array $deviceIds): Collection
    {
        $bucketSeconds = config('dashboard.sparkline_bucket_seconds');
        $bucketCount = intdiv(config('dashboard.sparkline_hours') * 3600, $bucketSeconds);
        $startsAt = CarbonImmutable::now()->subSeconds($bucketCount * $bucketSeconds);
        $query = DeviceMetric::query();

        $rows = $query
            ->selectRaw($this->bucketExpression($query).' as bucket', [$startsAt->format('Y-m-d H:i:s'), $bucketSeconds])
            ->selectRaw('device_id, AVG(cpu) as cpu')
            ->whereIn('device_id', $deviceIds)
            ->where('recorded_at', '>=', $startsAt)
            ->groupBy('device_id', 'bucket')
            ->toBase()
            ->get()
            ->groupBy('device_id')
            ->map(fn (Collection $deviceRows): Collection => $deviceRows->keyBy(fn (object $row): int => (int) $row->bucket));

        return collect($deviceIds)->mapWithKeys(fn (int $deviceId): array => [
            $deviceId => collect(range(0, $bucketCount - 1))->map(fn (int $bucket): array => [
                'time' => $startsAt->addSeconds($bucket * $bucketSeconds)->toMutable()->toChartTime(),
                'cpu' => $this->rounded($rows->get($deviceId)?->get($bucket)?->cpu),
            ]),
        ]);
    }

    /**
     * Average CPU and RAM across the given devices per bucket over the fleet load window, in one query.
     *
     * @param  array<int, int>  $deviceIds
     * @return Collection<int, array{time: string, cpu: ?float, ram: ?float, threshold: int}>
     */
    public function fleetLoad(array $deviceIds): Collection
    {
        $bucketSeconds = config('dashboard.fleet_load_bucket_seconds');
        $bucketCount = intdiv(config('dashboard.fleet_load_hours') * 3600, $bucketSeconds);
        $startsAt = CarbonImmutable::now()->subSeconds($bucketCount * $bucketSeconds);
        $query = DeviceMetric::query();

        $buckets = $query
            ->selectRaw($this->bucketExpression($query).' as bucket', [$startsAt->format('Y-m-d H:i:s'), $bucketSeconds])
            ->selectRaw('AVG(cpu) as cpu, AVG(ram) as ram')
            ->whereIn('device_id', $deviceIds)
            ->where('recorded_at', '>=', $startsAt)
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->bucket);

        return collect(range(0, $bucketCount - 1))->map(fn (int $bucket): array => [
            'time' => $startsAt->addSeconds($bucket * $bucketSeconds)->toMutable()->toChartTime(),
            'cpu' => $this->rounded($buckets->get($bucket)?->cpu),
            'ram' => $this->rounded($buckets->get($bucket)?->ram),
            'threshold' => config('devices.load.warning_percent'),
        ]);
    }

    /**
     * The hero tile's mini bars: every hour grey, except the current one in red when it raised a critical.
     *
     * @param  Collection<int, array{time: string, warning: int, critical: int, total: int}>  $buckets
     * @return Collection<int, array{time: string, calm: int, hot: int}>
     */
    public function alertMiniBars(Collection $buckets): Collection
    {
        $lastIndex = $buckets->count() - 1;

        return $buckets->values()->map(function (array $bucket, int $index) use ($lastIndex): array {
            $isHot = $index === $lastIndex && $bucket['critical'] > 0;

            return ['time' => $bucket['time'], 'calm' => $isHot ? 0 : $bucket['total'], 'hot' => $isHot ? $bucket['total'] : 0];
        });
    }

    private function rounded(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
