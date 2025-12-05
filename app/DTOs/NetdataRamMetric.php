<?php

declare(strict_types=1);

namespace App\DTOs;

class NetdataRamMetric
{
    /**
     * Create a new RAM metric DTO from raw input.
     *
     * @param  mixed  $input  Raw input - can be float, string (JSON), or array
     */
    public function __construct(
        private readonly mixed $input
    ) {}

    /**
     * Parse the input and return the RAM usage percentage.
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

        // Primary approach: Calculate used% = used / (used + free + cached + buffers) * 100
        $used = 0.0;
        $denomNames = ['used', 'free', 'cached', 'buffers', 'available'];
        $sum = 0.0;

        foreach ($denomNames as $name) {
            $idx = array_search($name, $labels, true);
            if ($idx !== false) {
                $val = $last[$idx] ?? null;
                if (is_numeric($val)) {
                    $val = max(0.0, (float) $val);
                    $sum += $val;
                    if ($name === 'used') {
                        $used = $val;
                    }
                }
            }
        }

        if ($sum > 0.0 && $used > 0.0) {
            $pct = ($used / $sum) * 100.0;

            return max(0.0, min(100.0, round($pct, 2)));
        }

        // Fallback: Check for direct usage dimension
        $idx = array_search('usage', $labels, true);
        if ($idx !== false && isset($last[$idx]) && is_numeric($last[$idx])) {
            return max(0.0, min(100.0, round((float) $last[$idx], 2)));
        }

        return null;
    }
}
