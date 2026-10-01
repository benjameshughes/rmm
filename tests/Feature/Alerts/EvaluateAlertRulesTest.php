<?php

declare(strict_types=1);

use App\Actions\Alert\EvaluateAlertRules;
use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('triggers alert when CPU exceeds threshold for duration', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 1,
    ]);

    collect(range(5, 1))->each(fn (int $i) => DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now()->subMinutes($i),
    ]));

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(1);
    $alert = Alert::first();
    expect($alert->status)->toBe(AlertStatus::Triggered);
    expect($alert->current_value)->toBe(95.0);
    expect($alert->device_id)->toBe($device->id);
});

it('does not trigger alert when threshold not exceeded long enough', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 10,
    ]);

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(0);
});

it('auto-resolves alert when value drops below threshold', function (): void {
    $device = Device::factory()->active()->create();
    $rule = AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
    ]);

    $alert = Alert::factory()->triggered()->create([
        'alert_rule_id' => $rule->id,
        'device_id' => $device->id,
        'metric' => AlertMetric::Cpu,
    ]);

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 50.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    $alert->refresh();
    expect($alert->status)->toBe(AlertStatus::Resolved);
    expect($alert->resolved_at)->not->toBeNull();
});

it('does not create duplicate alert when one already exists', function (): void {
    $device = Device::factory()->active()->create();
    $rule = AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 1,
    ]);

    Alert::factory()->triggered()->create([
        'alert_rule_id' => $rule->id,
        'device_id' => $device->id,
        'current_value' => 92.0,
    ]);

    DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 91.0,
        'recorded_at' => now()->subMinutes(2),
    ]);

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(1);
    expect(Alert::first()->current_value)->toBe(95.0);
});

it('skips inactive rules', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->cpu()->inactive()->create([
        'threshold' => 10,
        'duration_minutes' => 1,
    ]);

    DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now()->subMinutes(2),
    ]);

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(0);
});

it('resolves acknowledged alerts on recovery', function (): void {
    $device = Device::factory()->active()->create();
    $rule = AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
    ]);

    $alert = Alert::factory()->acknowledged()->create([
        'alert_rule_id' => $rule->id,
        'device_id' => $device->id,
        'metric' => AlertMetric::Cpu,
    ]);

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 50.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    $alert->refresh();
    expect($alert->status)->toBe(AlertStatus::Resolved);
});

it('triggers a disk alert once usage has stayed high for the duration', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->create([
        'metric' => AlertMetric::Disk,
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 5,
    ]);

    $metric = collect([6, 3, 0])
        ->map(function (int $minutesAgo) use ($device): DeviceMetric {
            $metric = DeviceMetric::factory()->create([
                'device_id' => $device->id,
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);
            $metric->diskMetrics()->create(['mount_point' => 'C:', 'usage_percent' => 95]);

            return $metric;
        })
        ->last();

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(1);
    expect(Alert::first()->metric)->toBe(AlertMetric::Disk);
});

it('honours durations longer than twenty readings', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 30,
    ]);

    collect(range(35, 1))->each(fn (int $minutesAgo) => DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 97.0,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]));

    $metric = DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 97.0,
        'recorded_at' => now(),
    ]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(1);
});

it('restarts the duration clock after a healthy reading', function (): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 5,
    ]);

    DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 95.0, 'recorded_at' => now()->subMinutes(8)]);
    DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 40.0, 'recorded_at' => now()->subMinutes(2)]);
    $metric = DeviceMetric::factory()->create(['device_id' => $device->id, 'cpu' => 95.0, 'recorded_at' => now()]);

    (new EvaluateAlertRules)($device, $metric);

    expect(Alert::count())->toBe(0);
});

it('only evaluates offline rules when checking for offline devices', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour()]);
    AlertRule::factory()->offline()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 10,
    ]);
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 1,
    ]);

    collect(range(70, 60))->each(fn (int $minutesAgo) => DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 99.0,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]));

    $this->artisan('devices:check-offline')->assertSuccessful();

    expect(Alert::count())->toBe(1);
    expect(Alert::first()->metric)->toBe(AlertMetric::Offline);
});
