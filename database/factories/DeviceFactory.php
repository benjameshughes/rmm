<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'hostname' => $this->faker->bothify('HOST-####'),
            'hardware_fingerprint' => Str::uuid()->toString(),
            'api_key' => null,
            'status' => Device::STATUS_PENDING,
            'os' => 'Windows 11 Pro',
            'last_ip' => $this->faker->ipv4(),
            'last_seen' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => Device::STATUS_ACTIVE,
            'api_key' => Str::random(64),
        ]);
    }
}

