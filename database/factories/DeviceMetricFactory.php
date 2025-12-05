<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceMetric>
 */
class DeviceMetricFactory extends Factory
{
    protected $model = DeviceMetric::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'cpu' => $this->faker->randomFloat(2, 0, 100),
            'ram' => $this->faker->randomFloat(2, 0, 100),
            'payload' => [
                'disks' => [],
                'network' => [],
            ],
            'recorded_at' => now(),
        ];
    }
}

