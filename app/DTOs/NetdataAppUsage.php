<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * Per-app CPU and memory from `app.cpu_utilization` and `app.mem_usage`
 * queries grouped by instance and dimension. Ids look like
 * `user,app.Netdata_Agent_cpu_utilization@<node>` and
 * `rss,app.Netdata_Agent_mem_usage@<node>`.
 */
final class NetdataAppUsage
{
    private NetdataV3Metrics $cpu;

    private NetdataV3Metrics $memory;

    public function __construct(mixed $cpu, mixed $memory)
    {
        $this->cpu = new NetdataV3Metrics($cpu);
        $this->memory = new NetdataV3Metrics($memory);
    }

    /**
     * @return array<int, array{name: string, cpu_percent: float|null, memory_mib: float|null}>
     */
    public function top(int $limit): array
    {
        return TopApps::pick($this->apps(), $limit);
    }

    /** @return Collection<string, array{name: string, cpu_percent: float|null, memory_mib: float|null}> */
    private function apps(): Collection
    {
        $cpu = $this->cpu->groupedDimensions('app', '_cpu_utilization')
            ->whereIn('dimension', ['user', 'system'])
            ->groupBy('instance')
            ->map(fn (Collection $dimensions): float => round($dimensions->sum('value'), 2));

        $memory = $this->memory->groupedDimensions('app', '_mem_usage')
            ->where('dimension', 'rss')
            ->pluck('value', 'instance')
            ->map(fn (float $mib): float => round($mib, 2));

        return $cpu->keys()
            ->merge($memory->keys())
            ->unique()
            ->mapWithKeys(fn (int|string $app): array => [$app => [
                'name' => str_replace('_', ' ', (string) $app),
                'cpu_percent' => $cpu->get($app),
                'memory_mib' => $memory->get($app),
            ]]);
    }
}
