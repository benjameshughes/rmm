<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\MetricRange;
use App\Models\Device;
use App\Models\DeviceDiskMetric;
use App\Models\DeviceMetric;
use App\Models\DeviceNetworkMetric;
use App\Queries\Concerns\BucketsReportTimes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chart series for one device, averaged into time buckets by the database so a
 * long range sends a few hundred points instead of every report. Buckets with
 * no reports are left out rather than drawn as zero.
 */
final class DeviceMetricQueries
{
    use BucketsReportTimes;

    /**
     * @return Collection<int, array{time: string, cpu: ?float, ram: ?float, cpuQueue: ?float, load: ?float, diskBusy: ?float, pageFile: ?float}>
     */
    public function performance(Device $device, MetricRange $range): Collection
    {
        $startsAt = $this->alignedStart($range);

        return $this->bucketed(DeviceMetric::query(), $startsAt, $range)
            ->where('device_id', $device->id)
            ->selectRaw('AVG(cpu) as cpu, AVG(ram) as ram, AVG(cpu_queue_length) as cpu_queue, AVG(load1) as load_average, AVG(disk_busy_percent) as disk_busy')
            ->selectRaw('AVG(CASE WHEN swap_total_mib > 0 THEN swap_used_mib * 100.0 / swap_total_mib END) as page_file')
            ->toBase()
            ->get()
            ->map(fn (object $bucket): array => [
                'time' => $this->bucketTime($startsAt, $range, (int) $bucket->bucket),
                'cpu' => $this->rounded($bucket->cpu),
                'ram' => $this->rounded($bucket->ram),
                'cpuQueue' => $this->rounded($bucket->cpu_queue),
                'load' => $this->rounded($bucket->load_average),
                'diskBusy' => $this->rounded($bucket->disk_busy),
                'pageFile' => $this->rounded($bucket->page_file),
            ]);
    }

    /**
     * Throughput summed across the device's adapters, averaged per report within each bucket.
     *
     * @return Collection<int, array{time: string, received: ?float, sent: ?float}>
     */
    public function network(Device $device, MetricRange $range): Collection
    {
        $startsAt = $this->alignedStart($range);

        return $this->bucketed(DeviceNetworkMetric::query()->join('device_metrics', 'device_metrics.id', '=', 'device_network_metrics.device_metric_id'), $startsAt, $range)
            ->where('device_metrics.device_id', $device->id)
            ->selectRaw('SUM(device_network_metrics.received_kbps) / COUNT(DISTINCT device_metrics.id) as received')
            ->selectRaw('SUM(device_network_metrics.sent_kbps) / COUNT(DISTINCT device_metrics.id) as sent')
            ->toBase()
            ->get()
            ->map(fn (object $bucket): array => [
                'time' => $this->bucketTime($startsAt, $range, (int) $bucket->bucket),
                'received' => $this->rounded($bucket->received),
                'sent' => $this->rounded($bucket->sent),
            ]);
    }

    /**
     * Throughput summed across the device's disks, averaged per report within each bucket.
     *
     * @return Collection<int, array{time: string, read: ?float, write: ?float}>
     */
    public function diskThroughput(Device $device, MetricRange $range): Collection
    {
        $startsAt = $this->alignedStart($range);

        return $this->bucketed(DeviceDiskMetric::query()->join('device_metrics', 'device_metrics.id', '=', 'device_disk_metrics.device_metric_id'), $startsAt, $range)
            ->where('device_metrics.device_id', $device->id)
            ->selectRaw('SUM(device_disk_metrics.read_kbps) / COUNT(DISTINCT device_metrics.id) as disk_read')
            ->selectRaw('SUM(device_disk_metrics.write_kbps) / COUNT(DISTINCT device_metrics.id) as disk_write')
            ->toBase()
            ->get()
            ->map(fn (object $bucket): array => [
                'time' => $this->bucketTime($startsAt, $range, (int) $bucket->bucket),
                'read' => $this->rounded($bucket->disk_read),
                'write' => $this->rounded($bucket->disk_write),
            ]);
    }

    private function bucketed(Builder $query, Carbon $startsAt, MetricRange $range): Builder
    {
        return $query
            ->selectRaw($this->bucketExpression($query).' as bucket', [$startsAt->format('Y-m-d H:i:s'), $range->bucketSeconds()])
            ->where('device_metrics.recorded_at', '>=', $startsAt)
            ->groupBy('bucket')
            ->orderBy('bucket');
    }

    /**
     * Snapped to a bucket boundary so the same buckets come back on every refresh.
     */
    private function alignedStart(MetricRange $range): Carbon
    {
        $bucketSeconds = $range->bucketSeconds();

        return Carbon::createFromTimestampUTC(intdiv($range->startsAt()->getTimestamp(), $bucketSeconds) * $bucketSeconds);
    }

    private function bucketTime(Carbon $startsAt, MetricRange $range, int $bucket): string
    {
        return $startsAt->copy()->addSeconds($bucket * $range->bucketSeconds())->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function rounded(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
