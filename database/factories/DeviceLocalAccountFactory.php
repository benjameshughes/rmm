<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceLocalAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceLocalAccount>
 */
class DeviceLocalAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->windows(),
            'sid' => 'S-1-5-21-1-2-3-'.fake()->unique()->numberBetween(1001, 99999),
            'username' => fake()->unique()->userName(),
            'full_name' => fake()->name(),
            'is_enabled' => true,
            'is_admin' => false,
            'is_signed_in' => false,
            'last_logon_at' => fake()->dateTimeBetween('-1 month'),
        ];
    }
}
