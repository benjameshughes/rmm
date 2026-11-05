<?php

namespace Database\Factories;

use App\Models\StaffAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StaffAccount> */
class StaffAccountFactory extends Factory
{
    protected $model = StaffAccount::class;

    public function definition(): array
    {
        return [
            'username' => fake()->unique()->lexify('????????'),
            'full_name' => fake()->name(),
            'password' => Str::password(16),
            'is_admin' => false,
            'is_enabled' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['is_admin' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }
}
