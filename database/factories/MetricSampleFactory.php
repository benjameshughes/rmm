<?php

namespace Database\Factories;

use App\Enums\MetricSampleType;
use App\Models\Device;
use App\Models\MetricSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSample>
 */
class MetricSampleFactory extends Factory
{
    protected $model = MetricSample::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'metric' => MetricSampleType::Cpu,
            'recorded_at' => now(),
            'value' => $this->faker->randomFloat(2, 0, 100),
        ];
    }

    public function metric(MetricSampleType $metric, float $value): static
    {
        return $this->state(fn (): array => [
            'metric' => $metric,
            'value' => $value,
        ]);
    }

    public function hoursAgo(int $hours): static
    {
        return $this->state(fn (): array => [
            'recorded_at' => now()->subHours($hours),
        ]);
    }
}
