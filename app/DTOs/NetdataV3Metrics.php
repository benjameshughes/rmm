<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One `/api/v3/data` response. Agents from 0.8.0 query a window of
 * per-second rows (`result.data`, newest first, null for gaps); older agents
 * sent a single averaged point with only `view.dimensions.sts.avg` relied on.
 * Usage values are averaged over the window's non-null points and state
 * values (uptime, disk space, link speed) come from the newest point, so both
 * shapes parse the same way.
 */
final class NetdataV3Metrics
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var Collection<int, array<string, float|null>>|null */
    private ?Collection $rows = null;

    public function __construct(mixed $input)
    {
        $this->data = match (true) {
            is_array($input) => $input,
            is_string($input) => $this->decodeJson($input),
            default => [],
        };
    }

    /** @return array<int, string> */
    public function getDimensionIds(): array
    {
        return $this->data['view']['dimensions']['ids'] ?? [];
    }

    /** @return array<int, float> */
    public function getAverageValues(): array
    {
        return $this->data['view']['dimensions']['sts']['avg'] ?? [];
    }

    /**
     * Each dimension averaged over the window's non-null points, or Netdata's
     * own average when the response carries no rows.
     *
     * @return array<string, float>
     */
    public function getDimensionAverages(): array
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            return $this->summaryAverages();
        }

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id): array => [$id => $this->column($id)->filter(fn (?float $value): bool => $value !== null)->avg()])
            ->filter(fn (?float $average): bool => $average !== null)
            ->all();
    }

    /**
     * Each dimension's newest non-null point, or Netdata's own average when
     * the response carries no rows.
     *
     * @return array<string, float>
     */
    public function getDimensionLatest(): array
    {
        $rows = $this->rows();

        if ($rows->isEmpty()) {
            return $this->summaryAverages();
        }

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id): array => [$id => $this->column($id)->first(fn (?float $value): bool => $value !== null)])
            ->filter(fn (?float $latest): bool => $latest !== null)
            ->all();
    }

    /**
     * Per-second rows keyed by unix time, newest first, each mapping dimension
     * id to its value. The columns follow `view.dimensions.ids`, whose ids
     * name the node by id where `result.labels` name it by hostname.
     *
     * @return Collection<int, array<string, float|null>>
     */
    public function rows(): Collection
    {
        return $this->rows ??= $this->parseRows();
    }

    /**
     * More than one point: a per-second window rather than one averaged point.
     */
    public function isWindow(): bool
    {
        return $this->rows()->count() > 1;
    }

    /**
     * A value worked out from each row, keyed by unix time, newest first.
     * Rows the calculation cannot use (every dimension a gap) are left out.
     *
     * @param  callable(array<string, float>): ?float  $calculate
     * @return Collection<int, float>
     */
    public function series(callable $calculate): Collection
    {
        return $this->rows()
            ->map(fn (array $row): array => array_filter($row, fn (?float $value): bool => $value !== null))
            ->reject(fn (array $row): bool => $row === [])
            ->map(fn (array $row): ?float => $calculate($row))
            ->filter(fn (?float $value): bool => $value !== null);
    }

    /** @return Collection<int, float> */
    public function cpuUsageSeries(): Collection
    {
        return $this->series($this->cpuUsageFrom(...));
    }

    /** @return Collection<int, float> */
    public function ramUsageSeries(): Collection
    {
        return $this->series($this->ramUsageFrom(...));
    }

    /** @return Collection<int, float> */
    public function swapUsageSeries(): Collection
    {
        return $this->series(fn (array $dimensions): ?float => isset($dimensions['used'], $dimensions['free']) && $dimensions['used'] + $dimensions['free'] > 0
            ? round($dimensions['used'] / ($dimensions['used'] + $dimensions['free']) * 100, 2)
            : null);
    }

    /**
     * One dimension's points as positive numbers: Netdata reports sent
     * traffic and blocked processes as negatives.
     *
     * @return Collection<int, float>
     */
    public function dimensionSeries(string $dimension): Collection
    {
        return $this->series(fn (array $dimensions): ?float => isset($dimensions[$dimension]) ? round(abs($dimensions[$dimension]), 2) : null);
    }

    public function getUnits(): ?string
    {
        $units = $this->data['view']['units'] ?? null;

        return is_string($units) ? $units : null;
    }

    public function getTitle(): ?string
    {
        return $this->data['view']['title'] ?? null;
    }

    public function hasData(): bool
    {
        return $this->getDimensionAverages() !== [];
    }

    /**
     * Linux hides idle and Windows reports it, so busy time is every other dimension summed.
     */
    public function parseCpuUsage(): ?float
    {
        return $this->hasData() ? $this->cpuUsageFrom($this->getDimensionAverages()) : null;
    }

    /** @return array<string, float|null> */
    public function getCpuDetails(): array
    {
        $dims = $this->getDimensionAverages();

        $system = $dims['system'] ?? null;
        $dpc = $dims['dpc'] ?? null;
        if ($system !== null && $dpc !== null) {
            $system += $dpc;
        }

        return [
            'user' => $dims['user'] ?? null,
            'system' => $system,
            'nice' => $dims['nice'] ?? null,
            'iowait' => $dims['iowait'] ?? null,
            'irq' => $dims['irq'] ?? null,
            'softirq' => $dims['softirq'] ?? null,
            'steal' => $dims['steal'] ?? null,
            'idle' => $dims['idle'] ?? null,
        ];
    }

    /**
     * Linux splits memory into free, used, cached and buffers; Windows only free and used.
     */
    public function parseRamUsage(): ?float
    {
        return $this->hasData() ? $this->ramUsageFrom($this->getDimensionAverages()) : null;
    }

    /** @return array<string, float|null> */
    public function getMemoryDetails(): array
    {
        $dims = $this->getDimensionAverages();

        $used = $dims['used'] ?? null;
        $free = $dims['free'] ?? null;
        $cached = $dims['cached'] ?? null;
        $buffers = $dims['buffers'] ?? null;

        $total = ($used !== null && $free !== null)
            ? $used + $free + ($cached ?? 0) + ($buffers ?? 0)
            : null;

        return [
            'used_mib' => $used,
            'free_mib' => $free,
            'cached_mib' => $cached,
            'buffers_mib' => $buffers,
            'available_mib' => $dims['available'] ?? null,
            'total_mib' => $total,
        ];
    }

    /** @return array<string, float|null> */
    public function parseLoadAverages(): array
    {
        $dims = $this->getDimensionAverages();

        return [
            'load1' => $dims['load1'] ?? null,
            'load5' => $dims['load5'] ?? null,
            'load15' => $dims['load15'] ?? null,
        ];
    }

    public function parseUptime(): ?float
    {
        return collect($this->getDimensionLatest())->first();
    }

    /**
     * Running and blocked process counts at the newest point, from `system.processes`.
     *
     * @return array{running: int|null, blocked: int|null}
     */
    public function parseProcesses(): array
    {
        $latest = $this->getDimensionLatest();

        return [
            'running' => isset($latest['running']) ? (int) round(abs($latest['running'])) : null,
            'blocked' => isset($latest['blocked']) ? (int) round(abs($latest['blocked'])) : null,
        ];
    }

    /**
     * Per-volume disk rows from a `disk.space` query grouped by instance and
     * dimension. Responses grouped by dimension only average every volume
     * together, so they yield nothing.
     *
     * @param  array<int, string>  $ignoredVolumePatterns
     * @return array<int, array{mount_point: string, used_gb: float, available_gb: float, total_gb: float, usage_percent: float|null}>
     */
    public function parseDiskVolumes(array $ignoredVolumePatterns = []): array
    {
        return $this->groupedLatestDimensions('disk_space')
            ->reject(fn (array $dimension): bool => Str::is($ignoredVolumePatterns, $dimension['instance']))
            ->groupBy('instance')
            ->map(fn (Collection $dimensions, string $volume): array => $this->diskVolumeRow(
                $volume,
                (float) ($dimensions->firstWhere('dimension', 'used')['value'] ?? 0),
                (float) ($dimensions->firstWhere('dimension', 'avail')['value'] ?? 0),
            ))
            ->values()
            ->all();
    }

    /**
     * Inode usage per mount point at the newest point, from a `disk.inodes`
     * query grouped by instance and dimension. Like disk space, inodes
     * reserved for root are left out, as `df` does.
     *
     * @return Collection<string, float> mount point => percent used
     */
    public function parseInodeUsage(): Collection
    {
        return $this->groupedLatestDimensions('disk_inodes')
            ->groupBy('instance')
            ->map(fn (Collection $dimensions): array => [
                'used' => (float) ($dimensions->firstWhere('dimension', 'used')['value'] ?? 0),
                'avail' => (float) ($dimensions->firstWhere('dimension', 'avail')['value'] ?? 0),
            ])
            ->filter(fn (array $inodes): bool => $inodes['used'] + $inodes['avail'] > 0)
            ->map(fn (array $inodes): float => round($inodes['used'] / ($inodes['used'] + $inodes['avail']) * 100, 2));
    }

    /**
     * Dimensions of a query grouped by instance and dimension, averaged over
     * the window, whose ids look like `<dimension>,<prefix>.<instance><suffix>@<node>`.
     * Ungrouped ids (just `<dimension>`) never match, so averaged-together data is dropped.
     *
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    public function groupedDimensions(string $prefix, string $suffix = ''): Collection
    {
        return $this->grouped($this->getDimensionAverages(), $prefix, $suffix);
    }

    /**
     * Grouped dimensions at the window's newest point, for state such as
     * disk space or link speed.
     *
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    public function groupedLatestDimensions(string $prefix, string $suffix = ''): Collection
    {
        return $this->grouped($this->getDimensionLatest(), $prefix, $suffix);
    }

    /**
     * Windows has no load average; `system.processor_queue_length` is the
     * nearest equivalent (threads waiting for a CPU).
     */
    public function parseCpuQueueLength(): ?float
    {
        return $this->getDimensionAverages()['threads'] ?? null;
    }

    /** @return array{used_mib: float, total_mib: float}|null */
    public function parseSwap(): ?array
    {
        $averages = $this->getDimensionAverages();

        if (! isset($averages['used'], $averages['free']) || $averages['used'] + $averages['free'] <= 0) {
            return null;
        }

        return [
            'used_mib' => round($averages['used'], 2),
            'total_mib' => round($averages['used'] + $averages['free'], 2),
        ];
    }

    /**
     * Busy-time percentage of the busiest physical disk, from a `disk.util`
     * query grouped by instance and dimension.
     */
    public function parseBusiestDiskPercent(): ?float
    {
        $busiest = $this->groupedDimensions('disk_util')
            ->where('dimension', 'utilization')
            ->max('value');

        return $busiest === null ? null : max(0.0, min(100.0, round($busiest, 2)));
    }

    /**
     * Machine-wide throughput from a `system.net` query. Netdata reports sent
     * traffic as a negative number.
     *
     * @return array{received_kbps: float, sent_kbps: float}|null
     */
    public function parseNetworkTotals(): ?array
    {
        $averages = $this->getDimensionAverages();

        if (! isset($averages['received'], $averages['sent'])) {
            return null;
        }

        return [
            'received_kbps' => round(abs($averages['received']), 2),
            'sent_kbps' => round(abs($averages['sent']), 2),
        ];
    }

    /** @return array<string, mixed> */
    public function getRawData(): array
    {
        return $this->data;
    }

    /** @return Collection<int, array<string, float|null>> */
    private function parseRows(): Collection
    {
        $ids = $this->getDimensionIds();
        $data = $this->data['result']['data'] ?? [];

        if ($ids === [] || ! is_array($data)) {
            return collect();
        }

        return collect($data)
            ->filter(fn (mixed $row): bool => is_array($row) && count($row) === count($ids) + 1 && is_numeric($row[0]))
            ->mapWithKeys(fn (array $row): array => [(int) $row[0] => array_combine($ids, array_map(
                fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null,
                array_slice($row, 1),
            ))])
            ->sortKeysDesc();
    }

    /**
     * One dimension's points, newest first. Ids hold dots, so they cannot go through pluck().
     *
     * @return Collection<int, float|null>
     */
    private function column(string $id): Collection
    {
        return $this->rows()->map(fn (array $row): ?float => $row[$id] ?? null);
    }

    /** @return array<string, float> */
    private function summaryAverages(): array
    {
        $averages = $this->getAverageValues();

        return collect($this->getDimensionIds())
            ->mapWithKeys(fn (string $id, int $i): array => isset($averages[$i]) ? [$id => (float) $averages[$i]] : [])
            ->all();
    }

    /**
     * @param  array<string, float>  $values
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    private function grouped(array $values, string $prefix, string $suffix): Collection
    {
        $pattern = '/^(?<dimension>[^,]+),'.preg_quote($prefix, '/').'\.(?<instance>.+)'.preg_quote($suffix, '/').'@[^@]+$/';

        return collect($values)
            ->map(fn (float $value, string $id): ?array => preg_match($pattern, $id, $match)
                ? ['instance' => $match['instance'], 'dimension' => $match['dimension'], 'value' => $value]
                : null)
            ->filter()
            ->values();
    }

    /** @param array<string, float> $dimensions */
    private function cpuUsageFrom(array $dimensions): float
    {
        $busy = collect($dimensions)->reject(fn (float $value, string $name): bool => $name === 'idle')->sum();

        return max(0.0, min(100.0, round($busy, 2)));
    }

    /** @param array<string, float> $dimensions */
    private function ramUsageFrom(array $dimensions): ?float
    {
        $total = collect(['used', 'free', 'cached', 'buffers'])->sum(fn (string $name): float => $dimensions[$name] ?? 0.0);

        if ($total <= 0) {
            return null;
        }

        return max(0.0, min(100.0, round(($dimensions['used'] ?? 0.0) / $total * 100.0, 2)));
    }

    /** @return array{mount_point: string, used_gb: float, available_gb: float, total_gb: float, usage_percent: float|null} */
    private function diskVolumeRow(string $volume, float $usedGb, float $availableGb): array
    {
        $totalGb = $usedGb + $availableGb;

        return [
            'mount_point' => $volume,
            'used_gb' => round($usedGb, 2),
            'available_gb' => round($availableGb, 2),
            'total_gb' => round($totalGb, 2),
            'usage_percent' => $totalGb > 0 ? round($usedGb / $totalGb * 100, 2) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $input): array
    {
        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : [];
    }
}
