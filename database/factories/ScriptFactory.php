<?php

namespace Database\Factories;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Script> */
class ScriptFactory extends Factory
{
    protected $model = Script::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(ScriptCategory::cases()),
            'platform' => fake()->randomElement(ScriptPlatform::cases()),
            'script_type' => fake()->randomElement(ScriptType::cases()),
            'script_content' => 'echo "test"',
            'is_system' => false,
            'timeout_seconds' => fake()->randomElement([60, 120, 300]),
            'requires_admin' => fake()->boolean(30),
        ];
    }

    public function system(): static
    {
        return $this->state(fn (): array => [
            'is_system' => true,
        ]);
    }

    public function windows(): static
    {
        return $this->state(fn (): array => [
            'platform' => ScriptPlatform::Windows,
            'script_type' => fake()->randomElement([ScriptType::Powershell, ScriptType::Cmd]),
        ]);
    }

    public function linux(): static
    {
        return $this->state(fn (): array => [
            'platform' => ScriptPlatform::Linux,
            'script_type' => ScriptType::Bash,
        ]);
    }
}
