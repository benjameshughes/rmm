<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class NetdataV3Metrics
{
    /** @var array<string, mixed> */
    private array $data;

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

    /** @return array<string, float> */
    public function getDimensionAverages(): array
    {
        $ids = $this->getDimensionIds();
        $avgs = $this->getAverageValues();

        return collect($ids)
            ->mapWithKeys(fn (string $id, int $i) => isset($avgs[$i]) ? [$id => (float) $avgs[$i]] : [])
            ->all();
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
        return ! empty($this->getDimensionIds()) && ! empty($this->getAverageValues());
    }

    public function parseCpuUsage(): ?float
    {
        if (! $this->hasData()) {
            return null;
        }

        $total = collect($this->getDimensionAverages())
            ->reject(fn (float $value, string $name): bool => $name === 'idle')
            ->sum();

        return max(0.0, min(100.0, round($total, 2)));
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

    public function parseRamUsage(): ?float
    {
        if (! $this->hasData()) {
            return null;
        }

        $dims = $this->getDimensionAverages();
        $used = $dims['used'] ?? 0.0;
        $total = ($dims['used'] ?? 0.0) + ($dims['free'] ?? 0.0) + ($dims['cached'] ?? 0.0) + ($dims['buffers'] ?? 0.0);

        if ($total <= 0) {
            return null;
        }

        return max(0.0, min(100.0, round(($used / $total) * 100.0, 2)));
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
        if (! $this->hasData()) {
            return null;
        }

        return $this->getAverageValues()[0] ?? null;
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
        return $this->groupedDimensions('disk_space')
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
     * Dimensions of a query grouped by instance and dimension, whose ids look
     * like `<dimension>,<prefix>.<instance><suffix>@<node>`. Ungrouped ids
     * (just `<dimension>`) never match, so averaged-together data is dropped.
     *
     * @return Collection<int, array{instance: string, dimension: string, value: float}>
     */
    public function groupedDimensions(string $prefix, string $suffix = ''): Collection
    {
        $pattern = '/^(?<dimension>[^,]+),'.preg_quote($prefix, '/').'\.(?<instance>.+)'.preg_quote($suffix, '/').'@[^@]+$/';

        return collect($this->getDimensionAverages())
            ->map(fn (float $value, string $id): ?array => preg_match($pattern, $id, $match)
                ? ['instance' => $match['instance'], 'dimension' => $match['dimension'], 'value' => $value]
                : null)
            ->filter()
            ->values();
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
