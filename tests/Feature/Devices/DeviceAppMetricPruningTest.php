<?php

declare(strict_types=1);

use App\Models\DeviceAppMetric;
use App\Models\DeviceMetric;

it('prunes app rows older than the configured history', function (): void {
    config(['devices.metrics.app_history_hours' => 24]);
    $metric = DeviceMetric::factory()->create();

    $stale = DeviceAppMetric::factory()->for($metric)->hoursAgo(25)->create();
    $recent = DeviceAppMetric::factory()->for($metric)->hoursAgo(23)->create();

    $this->artisan('model:prune', ['--model' => [DeviceAppMetric::class]])->assertSuccessful();

    expect(DeviceAppMetric::pluck('id')->all())->toBe([$recent->id])
        ->and(DeviceMetric::whereKey($metric->id)->exists())->toBeTrue()
        ->and(DeviceAppMetric::find($stale->id))->toBeNull();
});

it('follows the configured history window', function (): void {
    config(['devices.metrics.app_history_hours' => 2]);
    DeviceAppMetric::factory()->hoursAgo(3)->create();

    expect((new DeviceAppMetric)->prunable()->count())->toBe(1);
});

it('removes app rows with their metric', function (): void {
    $app = DeviceAppMetric::factory()->create();

    $app->deviceMetric->delete();

    expect(DeviceAppMetric::count())->toBe(0);
});

it('prunes app history every hour', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain("model:prune --model='App\\Models\\DeviceAppMetric'")
        ->assertSuccessful();
});
