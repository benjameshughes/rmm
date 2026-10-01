<?php

declare(strict_types=1);

use App\Actions\Device\StoreDeviceMetrics;
use App\Enums\AlertOperator;
use App\Enums\AlertStatus;
use App\Events\MetricsReceived;
use App\Listeners\EvaluateAlertRulesForMetrics;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

it('announces received metrics in both payload formats', function (array $input): void {
    Event::fake([MetricsReceived::class]);
    $device = Device::factory()->active()->create();

    $metric = app(StoreDeviceMetrics::class)($device, $input, '10.0.0.5');

    Event::assertDispatchedTimes(MetricsReceived::class, 1);
    Event::assertDispatched(MetricsReceived::class, fn (MetricsReceived $event): bool => $event->device->is($device) && $event->metric->is($metric));
})->with([
    'standard' => [['cpu' => ['usage_percent' => 12], 'ram' => ['usage_percent' => 34]]],
    'raw netdata' => [['netdata_cpu' => []]],
]);

it('evaluates alert rules when metrics arrive', function (): void {
    Event::fake();

    Event::assertListening(MetricsReceived::class, EvaluateAlertRulesForMetrics::class);
});

it('raises an alert end to end from a metrics post', function (): void {
    $device = Device::factory()->withApiKey('KEY-METRICS')->create();
    AlertRule::factory()->cpu()->create([
        'operator' => AlertOperator::GreaterThan,
        'threshold' => 90,
        'duration_minutes' => 1,
    ]);
    collect(range(3, 1))->each(fn (int $minutesAgo) => DeviceMetric::factory()->create([
        'device_id' => $device->id,
        'cpu' => 95.0,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]));

    $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 97], 'ram' => ['usage_percent' => 10]], ['X-Agent-Key' => 'KEY-METRICS'])
        ->assertSuccessful();

    $alert = Alert::query()->where('device_id', $device->id)->sole();
    expect($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->current_value)->toBe(97.0);
});
