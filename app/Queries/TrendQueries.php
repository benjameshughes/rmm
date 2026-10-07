<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Trends\DeviceTrendStats;
use App\DTOs\Trends\PeriodStats;
use App\DTOs\Trends\TrendChange;
use App\DTOs\Trends\TrendWindow;
use App\Enums\CommandStatus;
use App\Enums\DeviceStatus;
use App\Enums\TrendChangeKind;
use App\Enums\TrendScope;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceMetric;
use App\Models\DeviceSoftware;
use App\Queries\Concerns\BucketsReportTimes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read models for the Trends page. Every figure is aggregated by the database from
 * device_metrics, using window functions both MariaDB and SQLite run, so a month of
 * reports never comes back to PHP row by row.
 */
final class TrendQueries
{
    use BucketsReportTimes;

    /**
     * Each scoped device's figures for both periods, keyed by device ID. A device with
     * reports in only one period gets an empty set for the other.
     *
     * @return Collection<int, DeviceTrendStats>
     */
    public function deviceStats(TrendWindow $window, TrendScope $scope): Collection
    {
        return DeviceMetric::query()
            ->fromSub($this->rankedReports($window, $scope), 'ranked')
            ->select(['device_id', 'is_current'])
            ->selectRaw('COUNT(*) as reports')
            ->selectRaw('SUM(CASE WHEN cpu IS NULL THEN 1 ELSE 0 END) as blank_reports')
            ->selectRaw('AVG(cpu) as cpu, AVG(ram) as ram, AVG(swap_used_mib) as swap_mib, AVG(disk_busy_percent) as disk_busy')
            ->selectRaw('MIN(CASE WHEN cpu_rank * 100 >= cpu_count * ? THEN cpu END) as cpu_peak', [config('trends.peak_percentile')])
            ->selectRaw('SUM(CASE WHEN uptime_seconds + ? < previous_uptime THEN 1 ELSE 0 END) as reboots', [config('trends.reboot_uptime_tolerance_seconds')])
            ->selectRaw('COUNT(DISTINCT online_bucket) as online_buckets')
            ->groupBy('device_id', 'is_current')
            ->toBase()
            ->get()
            ->groupBy('device_id')
            ->map(fn (Collection $periods, int|string $deviceId): DeviceTrendStats => new DeviceTrendStats(
                deviceId: (int) $deviceId,
                previous: $this->periodStats($periods->firstWhere('is_current', 0)),
                current: $this->periodStats($periods->firstWhere('is_current', 1)),
            ));
    }

    /**
     * Fleet RAM and CPU per bucket, the earlier period laid over the latest so the
     * same point in each lines up on one time axis.
     *
     * @param  array<int, int>  $deviceIds
     * @return Collection<int, array{time: string, ram: ?float, previousRam: ?float, cpu: ?float, previousCpu: ?float}>
     */
    public function fleetSeries(TrendWindow $window, array $deviceIds): Collection
    {
        $query = DeviceMetric::query();
        $bucketCount = $window->bucketCount();

        $buckets = $query
            ->selectRaw($this->bucketExpression($query).' as bucket', [$window->previousStartsAt->format('Y-m-d H:i:s'), $window->period->bucketSeconds()])
            ->selectRaw('AVG(cpu) as cpu, AVG(ram) as ram')
            ->whereIn('device_id', $deviceIds)
            ->where('recorded_at', '>=', $window->previousStartsAt)
            ->where('recorded_at', '<', $window->endsAt)
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->keyBy(fn (object $bucket): int => (int) $bucket->bucket);

        return collect(range(0, $bucketCount - 1))
            ->map(fn (int $bucket): array => [
                'time' => $window->currentStartsAt->copy()->addSeconds($bucket * $window->period->bucketSeconds())->utc()->format('Y-m-d\TH:i:s\Z'),
                'ram' => $this->rounded($buckets->get($bucket + $bucketCount)?->ram),
                'previousRam' => $this->rounded($buckets->get($bucket)?->ram),
                'cpu' => $this->rounded($buckets->get($bucket + $bucketCount)?->cpu),
                'previousCpu' => $this->rounded($buckets->get($bucket)?->cpu),
            ]);
    }

    /**
     * Commands that finished cleanly in the latest period, grouped into what they changed:
     * a package by action across every device it touched, each script, and ad-hoc commands.
     * Commands that only read and report are left out. Newest change first.
     *
     * @return Collection<int, TrendChange>
     */
    public function changes(TrendWindow $window, TrendScope $scope): Collection
    {
        $commands = DeviceCommand::query()
            ->with(['script', 'device'])
            ->where('status', CommandStatus::Completed)
            ->where('exit_code', 0)
            ->where('completed_at', '>=', $window->currentStartsAt)
            ->where('completed_at', '<', $window->endsAt)
            ->whereIn('device_id', $this->scopedDeviceIds($scope))
            ->where(fn (Builder $scriptQuery): Builder => $scriptQuery
                ->whereNull('script_id')
                ->orWhereDoesntHave('script', fn (Builder $ignoredQuery): Builder => $ignoredQuery->whereIn('slug', config('trends.ignored_script_slugs'))))
            ->latest('completed_at')
            ->latest('id')
            ->get();

        $packageNames = $this->packageNames($commands);

        return $commands
            ->groupBy(fn (DeviceCommand $command): string => $this->changeKey($command))
            ->map(fn (Collection $group, string $key): TrendChange => $this->changeFrom($key, $group, $packageNames))
            ->sortByDesc(fn (TrendChange $change): int => $change->lastAt->getTimestamp())
            ->values();
    }

    /** @return Builder<Device> */
    public function scopedDeviceIds(TrendScope $scope): Builder
    {
        return $scope->apply(Device::query()->where('status', DeviceStatus::Active))->select('id');
    }

    /**
     * Every report in both periods, tagged with its period, its online slot, the uptime of the
     * report before it and its CPU rank within its period (blank CPU ranked last).
     */
    private function rankedReports(TrendWindow $window, TrendScope $scope): Builder
    {
        $query = DeviceMetric::query();
        $currentStartsAt = $window->currentStartsAt->format('Y-m-d H:i:s');
        $period = 'CASE WHEN recorded_at >= ? THEN 1 ELSE 0 END';

        return $query
            ->select(['device_id', 'cpu', 'ram', 'swap_used_mib', 'disk_busy_percent', 'uptime_seconds'])
            ->selectRaw("{$period} as is_current", [$currentStartsAt])
            ->selectRaw($this->bucketExpression($query).' as online_bucket', [$window->previousStartsAt->format('Y-m-d H:i:s'), config('trends.online_bucket_seconds')])
            ->selectRaw('LAG(uptime_seconds) OVER (PARTITION BY device_id ORDER BY recorded_at, id) as previous_uptime')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY device_id, {$period} ORDER BY CASE WHEN cpu IS NULL THEN 1 ELSE 0 END, cpu) as cpu_rank", [$currentStartsAt])
            ->selectRaw("COUNT(cpu) OVER (PARTITION BY device_id, {$period}) as cpu_count", [$currentStartsAt])
            ->whereIn('device_id', $this->scopedDeviceIds($scope))
            ->where('recorded_at', '>=', $window->previousStartsAt)
            ->where('recorded_at', '<', $window->endsAt);
    }

    private function periodStats(?object $row): PeriodStats
    {
        return $row === null ? new PeriodStats : PeriodStats::fromRow($row);
    }

    private function changeKey(DeviceCommand $command): string
    {
        return match (TrendChangeKind::of($command)) {
            TrendChangeKind::AdHoc => 'ad-hoc',
            TrendChangeKind::Script => "script-{$command->script_id}",
            default => $command->script->slug.'|'.$this->packageId($command),
        };
    }

    /**
     * @param  Collection<int, DeviceCommand>  $group  newest first
     * @param  Collection<string, string>  $packageNames
     */
    private function changeFrom(string $key, Collection $group, Collection $packageNames): TrendChange
    {
        $command = $group->first();
        $kind = TrendChangeKind::of($command);
        $packageId = $this->packageId($command);

        return new TrendChange(
            key: $key,
            kind: $kind,
            subject: match ($kind) {
                TrendChangeKind::AdHoc => 'Ad-hoc commands',
                TrendChangeKind::Script => $command->displayName(),
                default => $packageNames->get($packageId, $packageId),
            },
            href: match ($kind) {
                TrendChangeKind::AdHoc => null,
                TrendChangeKind::Script => route('scripts.show', $command->script_id),
                default => route('software.show', ['id' => $packageId]),
            },
            latestCommands: $group->unique('device_id')->values(),
            runsByDevice: $group->countBy('device_id')->all(),
            runCount: $group->count(),
            isScheduled: $group->contains(fn (DeviceCommand $run): bool => $run->scheduled_task_id !== null),
            firstAt: $group->min('completed_at'),
            lastAt: $group->max('completed_at'),
        );
    }

    /**
     * Friendly names for the packages touched, from whichever device still lists them.
     * An uninstalled package may be gone from every device, so its ID stands in.
     *
     * @param  Collection<int, DeviceCommand>  $commands
     * @return Collection<string, string>
     */
    private function packageNames(Collection $commands): Collection
    {
        $packageIds = $commands
            ->filter(fn (DeviceCommand $command): bool => $command->packageAction() !== null)
            ->map($this->packageId(...))
            ->unique()
            ->values();

        if ($packageIds->isEmpty()) {
            return collect();
        }

        return DeviceSoftware::query()
            ->whereIn('package_id', $packageIds)
            ->pluck('name', 'package_id');
    }

    private function packageId(DeviceCommand $command): string
    {
        return (string) ($command->parameters['PackageId'] ?? 'Unknown package');
    }

    private function rounded(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
