<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => AuditAction::DeviceUpdated,
            'subject_type' => null,
            'subject_id' => null,
            'properties' => ['label' => fake()->domainWord()],
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function daysAgo(int $days): static
    {
        return $this->state(fn (): array => ['created_at' => now()->subDays($days)]);
    }
}
