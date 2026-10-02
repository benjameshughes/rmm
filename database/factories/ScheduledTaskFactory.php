<?php

namespace Database\Factories;

use App\Enums\ScheduledTaskAction;
use App\Enums\ScheduleTargetType;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledTask> */
class ScheduledTaskFactory extends Factory
{
    protected $model = ScheduledTask::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'action' => ScheduledTaskAction::RunScript,
            'script_id' => Script::factory(),
            'cron_expression' => '0 2 * * *',
            'target_type' => ScheduleTargetType::All,
            'target_id' => null,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function wake(): static
    {
        return $this->state(fn (): array => [
            'action' => ScheduledTaskAction::Wake,
            'script_id' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
