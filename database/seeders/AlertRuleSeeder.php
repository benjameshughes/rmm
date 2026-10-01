<?php

namespace Database\Seeders;

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            [
                'name' => 'High CPU',
                'metric' => AlertMetric::Cpu,
                'operator' => AlertOperator::GreaterThan,
                'threshold' => 90,
                'duration_minutes' => 5,
                'severity' => AlertSeverity::Critical,
            ],
            [
                'name' => 'CPU Warning',
                'metric' => AlertMetric::Cpu,
                'operator' => AlertOperator::GreaterThan,
                'threshold' => 75,
                'duration_minutes' => 10,
                'severity' => AlertSeverity::Warning,
            ],
            [
                'name' => 'High RAM',
                'metric' => AlertMetric::Ram,
                'operator' => AlertOperator::GreaterThan,
                'threshold' => 90,
                'duration_minutes' => 5,
                'severity' => AlertSeverity::Critical,
            ],
            [
                'name' => 'Disk Full',
                'metric' => AlertMetric::Disk,
                'operator' => AlertOperator::GreaterThan,
                'threshold' => 90,
                'duration_minutes' => 1,
                'severity' => AlertSeverity::Critical,
            ],
            [
                'name' => 'Device Offline',
                'metric' => AlertMetric::Offline,
                'operator' => AlertOperator::GreaterThan,
                'threshold' => 5,
                'duration_minutes' => 1,
                'severity' => AlertSeverity::Warning,
            ],
        ])->each(fn (array $rule) => AlertRule::create($rule));
    }
}
