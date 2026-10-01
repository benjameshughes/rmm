<?php

namespace Database\Factories;

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'metric' => fake()->randomElement(AlertMetric::cases()),
            'operator' => AlertOperator::GreaterThan,
            'threshold' => fake()->randomFloat(1, 50, 95),
            'duration_minutes' => fake()->randomElement([1, 5, 10, 15]),
            'severity' => fake()->randomElement(AlertSeverity::cases()),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function cpu(): static
    {
        return $this->state(fn (): array => ['metric' => AlertMetric::Cpu]);
    }

    public function ram(): static
    {
        return $this->state(fn (): array => ['metric' => AlertMetric::Ram]);
    }

    public function offline(): static
    {
        return $this->state(fn (): array => ['metric' => AlertMetric::Offline]);
    }
}
