<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\MetricSampleType;
use App\Models\Device;
use App\Models\MetricSample;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates over a device's per-second samples, worked out by the database.
 * Null when the device has no samples in the period.
 */
final class MetricSampleQueries
{
    public function peak(Device $device, MetricSampleType $metric, CarbonInterface $since): ?float
    {
        return $this->rounded($this->since($device, $metric, $since)->max('value'));
    }

    public function average(Device $device, MetricSampleType $metric, CarbonInterface $since): ?float
    {
        return $this->rounded($this->since($device, $metric, $since)->avg('value'));
    }

    /** @return Builder<MetricSample> */
    private function since(Device $device, MetricSampleType $metric, CarbonInterface $since): Builder
    {
        return MetricSample::query()
            ->where('device_id', $device->id)
            ->where('metric', $metric)
            ->where('recorded_at', '>=', $since);
    }

    private function rounded(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
