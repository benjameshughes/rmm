<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Per-volume disk readings from one Netdata response grouped by instance and
 * dimension: space (`disk.space`), inodes (`disk.inodes`) and busy time
 * (`disk.util`).
 */
final class NetdataDiskMetrics
{
    private NetdataV3Metrics $response;

    public function __construct(mixed $input)
    {
        $this->response = new NetdataV3Metrics($input);
    }

    /**
     * Responses grouped by dimension only average every volume together, so
     * they yield nothing.
     *
     * @param  array<int, string>  $ignoredVolumePatterns
     * @return array<int, array{mount_point: string, used_gb: float, available_gb: float, total_gb: float, usage_percent: float|null}>
     */
    public function parseDiskVolumes(array $ignoredVolumePatterns = []): array
    {
        return $this->response->groupedLatestDimensions('disk_space')
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
     * Like disk space, inodes reserved for root are left out, as `df` does.
     *
     * @return Collection<string, float> mount point => percent used
     */
    public function parseInodeUsage(): Collection
    {
        return $this->response->groupedLatestDimensions('disk_inodes')
            ->groupBy('instance')
            ->map(fn (Collection $dimensions): array => [
                'used' => (float) ($dimensions->firstWhere('dimension', 'used')['value'] ?? 0),
                'avail' => (float) ($dimensions->firstWhere('dimension', 'avail')['value'] ?? 0),
            ])
            ->filter(fn (array $inodes): bool => $inodes['used'] + $inodes['avail'] > 0)
            ->map(fn (array $inodes): float => round($inodes['used'] / ($inodes['used'] + $inodes['avail']) * 100, 2));
    }

    /**
     * Busy-time percentage of the busiest physical disk.
     */
    public function parseBusiestDiskPercent(): ?float
    {
        $busiest = $this->response->groupedDimensions('disk_util')
            ->where('dimension', 'utilization')
            ->max('value');

        return $busiest === null ? null : max(0.0, min(100.0, round($busiest, 2)));
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
}
