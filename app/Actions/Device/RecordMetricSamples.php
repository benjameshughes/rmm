<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\DTOs\NetdataV3Metrics;
use App\Enums\MetricSampleType;
use App\Models\Device;
use App\Models\MetricSample;
use Illuminate\Support\Carbon;

/**
 * Keeps the per-second points of each Netdata window an agent reports. Only
 * windows count: agents before 0.8.0 send one averaged point, which is not a
 * sample. Consecutive windows can overlap, so points are upserted on device,
 * metric and time in one statement.
 */
final class RecordMetricSamples
{
    /** @param array<string, mixed> $input A raw agent metrics request */
    public function __invoke(Device $device, array $input): int
    {
        $samples = collect(MetricSampleType::cases())
            ->flatMap(fn (MetricSampleType $metric): array => $this->samples($device, $metric, new NetdataV3Metrics($input[$metric->payloadKey()] ?? [])))
            ->all();

        if ($samples === []) {
            return 0;
        }

        MetricSample::query()->upsert($samples, ['device_id', 'metric', 'recorded_at'], ['value']);

        return count($samples);
    }

    /** @return array<int, array{device_id: int, metric: string, recorded_at: string, value: float}> */
    private function samples(Device $device, MetricSampleType $metric, NetdataV3Metrics $response): array
    {
        if (! $response->isWindow()) {
            return [];
        }

        return $metric->series($response)
            ->map(fn (float $value, int $timestamp): array => [
                'device_id' => $device->id,
                'metric' => $metric->value,
                'recorded_at' => Carbon::createFromTimestamp($timestamp)->toDateTimeString(),
                'value' => $value,
            ])
            ->values()
            ->all();
    }
}
