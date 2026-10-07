<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DevicePrinter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevicePrinter>
 */
final class DevicePrinterFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->randomElement(['Zebra GK420d - ZPL', 'XEROX3335 PCL6', 'HP Color LaserJet M553', 'Warehouse']);

        return [
            'device_id' => Device::factory()->active()->windows(),
            'name' => $name,
            'snapshot' => ['name' => $name, 'port_name' => 'USB001', 'driver_name' => $name, 'status' => 0, 'attributes' => 0, 'jobs_count' => 0, 'jobs' => []],
            'collected_at' => now(),
            'problem_since' => null,
        ];
    }

    /**
     * Windows flags the printer offline and it has been a problem for the given minutes.
     */
    public function offline(int $forMinutes = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'snapshot' => [...$attributes['snapshot'], 'status' => 0x80],
            'problem_since' => now()->subMinutes($forMinutes),
        ]);
    }
}
