<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Backups\BackupOverviewRow;
use App\DTOs\MetricChart;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\ServerBackupJob;
use App\Models\ServerBackupSnapshot;
use App\Queries\Concerns\BucketsReportTimes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read models for backups: each server job's snapshot charts, grouped into
 * time buckets by the database, and every backup across the fleet, PCs and
 * servers alike, for the Backups page.
 */
final class ServerBackupQueries
{
    use BucketsReportTimes;

    /**
     * Both charts for every job, in one query.
     *
     * @param  Collection<int, ServerBackupJob>  $jobs
     * @return Collection<int, array{size: MetricChart, added: MetricChart}> Keyed by job id
     */
    public function charts(Collection $jobs): Collection
    {
        $bucketSeconds = (int) ceil(config('backup.servers.chart_days') * 86400 / config('backup.servers.chart_points'));
        $startsAt = Carbon::createFromTimestampUTC(intdiv(now()->subDays(config('backup.servers.chart_days'))->getTimestamp(), $bucketSeconds) * $bucketSeconds);
        $query = ServerBackupSnapshot::query();

        $buckets = $jobs->isEmpty() ? collect() : $query
            ->selectRaw('server_backup_job_id')
            ->selectRaw($this->bucketExpression($query, 'server_backup_snapshots.taken_at').' as bucket', [$startsAt->format('Y-m-d H:i:s'), $bucketSeconds])
            ->selectRaw('AVG(total_bytes_processed) as size, SUM(data_added) as added')
            ->whereIn('server_backup_job_id', $jobs->modelKeys())
            ->where('taken_at', '>=', $startsAt)
            ->groupBy('server_backup_job_id', 'bucket')
            ->orderBy('bucket')
            ->toBase()
            ->get()
            ->groupBy('server_backup_job_id');

        return $jobs->mapWithKeys(function (ServerBackupJob $job) use ($buckets, $startsAt, $bucketSeconds): array {
            $rows = collect($buckets->get($job->id, []))->map(fn (object $bucket): array => [
                'time' => $startsAt->copy()->addSeconds((int) $bucket->bucket * $bucketSeconds)->utc()->format('Y-m-d\TH:i:s\Z'),
                'size' => $bucket->size === null ? null : (float) $bucket->size,
                'added' => $bucket->added === null ? null : (float) $bucket->added,
            ]);

            return [$job->id => ['size' => MetricChart::backupSize($rows), 'added' => MetricChart::backupDataAdded($rows)]];
        });
    }

    /**
     * Every server backup job and every PC with backups enabled on an
     * approved device, worst first.
     *
     * @return Collection<int, BackupOverviewRow>
     */
    public function fleet(): Collection
    {
        $serverJobs = ServerBackupJob::query()
            ->whereRelation('device', 'status', DeviceStatus::Active)
            ->with('device')
            ->get()
            ->map(BackupOverviewRow::forServerJob(...));

        $pcs = Device::query()
            ->where('status', DeviceStatus::Active)
            ->withBackupCredentials()
            ->with('latestBackupSnapshot')
            ->get()
            ->map(BackupOverviewRow::forPc(...));

        return $serverJobs->concat($pcs)
            ->sortBy([
                fn (BackupOverviewRow $first, BackupOverviewRow $second): int => $first->rank <=> $second->rank,
                fn (BackupOverviewRow $first, BackupOverviewRow $second): int => strcasecmp($first->device->hostname, $second->device->hostname),
                fn (BackupOverviewRow $first, BackupOverviewRow $second): int => strcmp($first->name, $second->name),
            ])
            ->values();
    }
}
