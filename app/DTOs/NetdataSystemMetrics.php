<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * Machine-wide readings from one Netdata response: CPU, RAM, swap, load,
 * uptime, processes and network totals.
 */
final class NetdataSystemMetrics
{
    private NetdataV3Metrics $response;

    public function __construct(mixed $input)
    {
        $this->response = $input instanceof NetdataV3Metrics ? $input : new NetdataV3Metrics($input);
    }

    /** @return Collection<int, float> */
    public function cpuUsageSeries(): Collection
    {
        return $this->response->series($this->cpuUsageFrom(...));
    }

    /** @return Collection<int, float> */
    public function ramUsageSeries(): Collection
    {
        return $this->response->series($this->ramUsageFrom(...));
    }

    /** @return Collection<int, float> */
    public function swapUsageSeries(): Collection
    {
        return $this->response->series(fn (array $dimensions): ?float => isset($dimensions['used'], $dimensions['free']) && $dimensions['used'] + $dimensions['free'] > 0
            ? round($dimensions['used'] / ($dimensions['used'] + $dimensions['free']) * 100, 2)
            : null);
    }

    /**
     * Linux hides idle and Windows reports it, so busy time is every other dimension summed.
     */
    public function parseCpuUsage(): ?float
    {
        return $this->response->hasData() ? $this->cpuUsageFrom($this->response->getDimensionAverages()) : null;
    }

    /** @return array<string, float|null> */
    public function getCpuDetails(): array
    {
        $dims = $this->response->getDimensionAverages();

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
        return $this->response->hasData() ? $this->ramUsageFrom($this->response->getDimensionAverages()) : null;
    }

    /** @return array<string, float|null> */
    public function getMemoryDetails(): array
    {
        $dims = $this->response->getDimensionAverages();

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
        $dims = $this->response->getDimensionAverages();

        return [
            'load1' => $dims['load1'] ?? null,
            'load5' => $dims['load5'] ?? null,
            'load15' => $dims['load15'] ?? null,
        ];
    }

    public function parseUptime(): ?float
    {
        return collect($this->response->getDimensionLatest())->first();
    }

    /**
     * Running and blocked process counts at the newest point, from `system.processes`.
     *
     * @return array{running: int|null, blocked: int|null}
     */
    public function parseProcesses(): array
    {
        $latest = $this->response->getDimensionLatest();

        return [
            'running' => isset($latest['running']) ? (int) round(abs($latest['running'])) : null,
            'blocked' => isset($latest['blocked']) ? (int) round(abs($latest['blocked'])) : null,
        ];
    }

    /**
     * Windows has no load average; `system.processor_queue_length` is the
     * nearest equivalent (threads waiting for a CPU).
     */
    public function parseCpuQueueLength(): ?float
    {
        return $this->response->getDimensionAverages()['threads'] ?? null;
    }

    /** @return array{used_mib: float, total_mib: float}|null */
    public function parseSwap(): ?array
    {
        $averages = $this->response->getDimensionAverages();

        if (! isset($averages['used'], $averages['free']) || $averages['used'] + $averages['free'] <= 0) {
            return null;
        }

        return [
            'used_mib' => round($averages['used'], 2),
            'total_mib' => round($averages['used'] + $averages['free'], 2),
        ];
    }

    /**
     * Machine-wide throughput from a `system.net` query. Netdata reports sent
     * traffic as a negative number.
     *
     * @return array{received_kbps: float, sent_kbps: float}|null
     */
    public function parseNetworkTotals(): ?array
    {
        $averages = $this->response->getDimensionAverages();

        if (! isset($averages['received'], $averages['sent'])) {
            return null;
        }

        return [
            'received_kbps' => round(abs($averages['received']), 2),
            'sent_kbps' => round(abs($averages['sent']), 2),
        ];
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
}
