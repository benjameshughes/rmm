<?php

namespace Database\Factories;

use App\Models\DeviceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceGroup> */
class DeviceGroupFactory extends Factory
{
    protected $model = DeviceGroup::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(['green', 'blue', 'amber', 'red', 'zinc', 'purple']),
        ];
    }
}
