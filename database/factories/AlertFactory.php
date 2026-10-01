<?php

namespace Database\Factories;

use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    protected $model = Alert::class;

    public function definition(): array
    {
        return [
            'alert_rule_id' => AlertRule::factory(),
            'device_id' => Device::factory(),
            'status' => AlertStatus::Triggered,
            'severity' => fake()->randomElement(AlertSeverity::cases()),
            'metric' => fake()->randomElement(AlertMetric::cases()),
            'threshold' => 90.0,
            'current_value' => fake()->randomFloat(1, 90, 100),
            'message' => fake()->sentence(),
            'triggered_at' => now(),
        ];
    }

    public function triggered(): static
    {
        return $this->state(fn (): array => ['status' => AlertStatus::Triggered]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (): array => [
            'status' => AlertStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => AlertStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
