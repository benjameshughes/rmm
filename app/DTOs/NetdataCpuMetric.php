<?php

declare(strict_types=1);

namespace App\DTOs;

class NetdataCpuMetric
{
    /**
     * Create a new CPU metric DTO from raw input.
     *
     * @param  mixed  $input  Raw input - can be float, string (JSON), or array
     */
    public function __construct(
        private readonly mixed $input
    ) {}

    /**
     * Parse the input and return the CPU usage percentage.
     * Returns null if the input cannot be parsed.
     */
    public function getUsagePercent(): ?float
    {
        // If already numeric, return it directly
        if (is_numeric($this->input)) {
            return max(0.0, min(100.0, round((float) $this->input, 2)));
        }

        // If not a string, cannot parse
        if (! is_string($this->input) || $this->input === '') {
            return null;
        }

        // Try to decode JSON
        try {
            $data = json_decode($this->input, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return $this->parseNetdataResponse($data);
    }

    /**
     * Parse a Netdata API response array.
     *
     * @param  array<string, mixed>  $data
     */
    private function parseNetdataResponse(array $data): ?float
    {
        // Handle both flat format and jsonwrap format (result key)
        $labels = $data['labels'] ?? ($data['result']['labels'] ?? null);
        $rows = $data['data'] ?? ($data['result']['data'] ?? null);

        if (! is_array($labels) || ! is_array($rows) || empty($rows)) {
            return null;
        }

        $last = end($rows);
        if (! is_array($last)) {
            return null;
        }

        // Primary approach: Find 'idle' dimension and compute 100 - idle
        $idleIndex = array_search('idle', $labels, true);
        if ($idleIndex !== false) {
            $idle = $last[$idleIndex] ?? null;
            if (is_numeric($idle)) {
                $usage = 100.0 - (float) $idle;

                return max(0.0, min(100.0, round($usage, 2)));
            }
        }

        // Fallback: Check for direct usage dimensions
        foreach (['usage', 'busy', 'user+system'] as $name) {
            $idx = array_search($name, $labels, true);
            if ($idx !== false) {
                $val = $last[$idx] ?? null;
                if (is_numeric($val)) {
                    return max(0.0, min(100.0, round((float) $val, 2)));
                }
            }
        }

        return null;
    }
}
