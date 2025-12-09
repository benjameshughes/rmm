<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * DTO for parsing Netdata v3 API responses.
 *
 * The v3 API returns data in this structure:
 * {
 *   "view": {
 *     "dimensions": {
 *       "ids": ["irq", "user", "system", "dpc"],
 *       "names": ["irq", "user", "system", "dpc"],
 *       "sts": {
 *         "min": [0, 0, 13.17, 0],
 *         "max": [0.98, 41.31, 41.51, 0.79],
 *         "avg": [0.21, 2.52, 15.79, 0.14],
 *         "con": [1.14, 13.51, 84.60, 0.73]
 *       }
 *     }
 *   }
 * }
 */
class NetdataV3Metrics
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(mixed $input)
    {
        if (is_string($input)) {
            try {
                $this->data = json_decode($input, true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (\Throwable) {
                $this->data = [];
            }
        } elseif (is_array($input)) {
            $this->data = $input;
        } else {
            $this->data = [];
        }
    }

    /**
     * Get the dimension IDs from the response.
     *
     * @return array<int, string>
     */
    public function getDimensionIds(): array
    {
        return $this->data['view']['dimensions']['ids'] ?? [];
    }

    /**
     * Get the average values for each dimension.
     *
     * @return array<int, float>
     */
    public function getAverageValues(): array
    {
        return $this->data['view']['dimensions']['sts']['avg'] ?? [];
    }

    /**
     * Get a map of dimension ID => average value.
     *
     * @return array<string, float>
     */
    public function getDimensionAverages(): array
    {
        $ids = $this->getDimensionIds();
        $avgs = $this->getAverageValues();

        $result = [];
        foreach ($ids as $i => $id) {
            if (isset($avgs[$i])) {
                $result[$id] = (float) $avgs[$i];
            }
        }

        return $result;
    }

    /**
     * Get the units for the view.
     */
    public function getUnits(): ?string
    {
        $units = $this->data['view']['units'] ?? null;

        return is_string($units) ? $units : null;
    }

    /**
     * Get the title of the view.
     */
    public function getTitle(): ?string
    {
        return $this->data['view']['title'] ?? null;
    }

    /**
     * Check if this response has valid dimension data.
     */
    public function hasData(): bool
    {
        return ! empty($this->getDimensionIds()) && ! empty($this->getAverageValues());
    }

    /**
     * Parse CPU usage percentage from system.cpu context.
     * CPU usage = sum of all non-idle dimensions.
     */
    public function parseCpuUsage(): ?float
    {
        if (! $this->hasData()) {
            return null;
        }

        $dims = $this->getDimensionAverages();
        $total = 0.0;

        foreach ($dims as $name => $value) {
            // Sum all non-idle values for total CPU usage
            if ($name !== 'idle') {
                $total += $value;
            }
        }

        return max(0.0, min(100.0, round($total, 2)));
    }

    /**
     * Get detailed CPU metrics.
     *
     * @return array<string, float|null>
     */
    public function getCpuDetails(): array
    {
        $dims = $this->getDimensionAverages();

        // Handle Windows "dpc" by adding it to system
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
     * Parse RAM usage percentage from system.ram context.
     * RAM usage = used / total * 100
     */
    public function parseRamUsage(): ?float
    {
        if (! $this->hasData()) {
            return null;
        }

        $dims = $this->getDimensionAverages();

        $used = $dims['used'] ?? 0.0;
        $free = $dims['free'] ?? 0.0;
        $cached = $dims['cached'] ?? 0.0;
        $buffers = $dims['buffers'] ?? 0.0;

        $total = $used + $free + $cached + $buffers;

        if ($total <= 0) {
            return null;
        }

        return max(0.0, min(100.0, round(($used / $total) * 100.0, 2)));
    }

    /**
     * Get detailed memory metrics (values in MiB).
     *
     * @return array<string, float|null>
     */
    public function getMemoryDetails(): array
    {
        $dims = $this->getDimensionAverages();

        $used = $dims['used'] ?? null;
        $free = $dims['free'] ?? null;
        $cached = $dims['cached'] ?? null;
        $buffers = $dims['buffers'] ?? null;
        $available = $dims['available'] ?? null;

        // Calculate total if we have the parts
        $total = null;
        if ($used !== null && $free !== null) {
            $total = $used + $free + ($cached ?? 0) + ($buffers ?? 0);
        }

        return [
            'used_mib' => $used,
            'free_mib' => $free,
            'cached_mib' => $cached,
            'buffers_mib' => $buffers,
            'available_mib' => $available,
            'total_mib' => $total,
        ];
    }

    /**
     * Parse load averages from system.load context.
     *
     * @return array<string, float|null>
     */
    public function parseLoadAverages(): array
    {
        $dims = $this->getDimensionAverages();

        return [
            'load1' => $dims['load1'] ?? null,
            'load5' => $dims['load5'] ?? null,
            'load15' => $dims['load15'] ?? null,
        ];
    }

    /**
     * Parse uptime from system.uptime context.
     */
    public function parseUptime(): ?float
    {
        if (! $this->hasData()) {
            return null;
        }

        $avgs = $this->getAverageValues();

        // Uptime usually has a single dimension
        return $avgs[0] ?? null;
    }

    /**
     * Get raw data for debugging.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->data;
    }
}
