<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\MetricSample;

it('prunes samples older than the configured retention', function (): void {
    config(['devices.metrics.sample_retention_hours' => 48]);
    $device = Device::factory()->create();

    $stale = MetricSample::factory()->for($device)->hoursAgo(49)->create();
    $recent = MetricSample::factory()->for($device)->hoursAgo(47)->create();

    $this->artisan('model:prune', ['--model' => [MetricSample::class]])->assertSuccessful();

    expect(MetricSample::pluck('id')->all())->toBe([$recent->id])
        ->and(Device::whereKey($device->id)->exists())->toBeTrue()
        ->and(MetricSample::find($stale->id))->toBeNull();
});

it('follows the configured retention window', function (): void {
    config(['devices.metrics.sample_retention_hours' => 2]);
    MetricSample::factory()->hoursAgo(3)->create();
    MetricSample::factory()->hoursAgo(1)->create();

    expect((new MetricSample)->prunable()->count())->toBe(1);
});

it('prunes samples every hour', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('App\\Models\\MetricSample')
        ->assertSuccessful();
});
