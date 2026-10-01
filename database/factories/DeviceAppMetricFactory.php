<?php

namespace Database\Factories;

use App\Models\DeviceAppMetric;
use App\Models\DeviceMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceAppMetric>
 */
class DeviceAppMetricFactory extends Factory
{
    protected $model = DeviceAppMetric::class;

    public function definition(): array
    {
        return [
            'device_metric_id' => DeviceMetric::factory(),
            'name' => $this->faker->randomElement(['Netdata Agent', 'Windows Update', 'Dell TechHub', 'Microsoft Defender Antivirus Service', 'desktop']),
            'cpu_percent' => $this->faker->randomFloat(2, 0, 25),
            'memory_mib' => $this->faker->randomFloat(2, 10, 2048),
        ];
    }

    public function hoursAgo(int $hours): static
    {
        return $this->state(fn (): array => [
            'created_at' => now()->subHours($hours),
            'updated_at' => now()->subHours($hours),
        ]);
    }
}
