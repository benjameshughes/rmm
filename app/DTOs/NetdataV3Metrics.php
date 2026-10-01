<?php

declare(strict_types=1);

namespace App\DTOs;

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

    /** @return array<string, mixed> */
    public function getRawData(): array
    {
        return $this->data;
    }

    private function decodeJson(string $input): array
    {
        try {
            return json_decode($input, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
