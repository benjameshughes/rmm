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
        $totalRamMib = $this->faker->randomElement([8192, 16384, 32768, 65536]);
        $usedRamMib = $totalRamMib * $this->faker->randomFloat(2, 0.3, 0.85);
        $ramPercent = ($usedRamMib / $totalRamMib) * 100;

        return [
            'device_id' => Device::factory(),
            'cpu' => $this->faker->randomFloat(2, 5, 95),
            'ram' => round($ramPercent, 2),
            'load1' => $this->faker->randomFloat(2, 0.1, 4.0),
            'load5' => $this->faker->randomFloat(2, 0.1, 3.5),
            'load15' => $this->faker->randomFloat(2, 0.1, 3.0),
            'uptime_seconds' => $this->faker->numberBetween(3600, 86400 * 90), // 1 hour to 90 days
            'memory_used_mib' => round($usedRamMib, 1),
            'memory_free_mib' => round($totalRamMib - $usedRamMib, 1),
            'memory_total_mib' => $totalRamMib,
            'alerts_normal' => $this->faker->numberBetween(5, 50),
            'alerts_warning' => $this->faker->numberBetween(0, 5),
            'alerts_critical' => $this->faker->numberBetween(0, 2),
            'agent_version' => '0.2.0',
            'payload' => [],
            'recorded_at' => now(),
        ];
    }

    public function healthy(): static
    {
        return $this->state(fn (): array => [
            'cpu' => $this->faker->randomFloat(2, 5, 40),
            'ram' => $this->faker->randomFloat(2, 20, 60),
            'load1' => $this->faker->randomFloat(2, 0.1, 1.5),
            'alerts_warning' => 0,
            'alerts_critical' => 0,
        ]);
    }

    public function stressed(): static
    {
        return $this->state(fn (): array => [
            'cpu' => $this->faker->randomFloat(2, 70, 95),
            'ram' => $this->faker->randomFloat(2, 80, 95),
            'load1' => $this->faker->randomFloat(2, 3.0, 8.0),
            'load5' => $this->faker->randomFloat(2, 2.5, 6.0),
            'load15' => $this->faker->randomFloat(2, 2.0, 5.0),
            'alerts_warning' => $this->faker->numberBetween(2, 8),
            'alerts_critical' => $this->faker->numberBetween(1, 3),
        ]);
    }

    public function withAlerts(int $warning = 0, int $critical = 0): static
    {
        return $this->state(fn (): array => [
            'alerts_warning' => $warning,
            'alerts_critical' => $critical,
        ]);
    }

    public function hoursAgo(int $hours): static
    {
        return $this->state(fn (): array => [
            'recorded_at' => now()->subHours($hours),
        ]);
    }

    public function minutesAgo(int $minutes): static
    {
        return $this->state(fn (): array => [
            'recorded_at' => now()->subMinutes($minutes),
        ]);
    }
}
