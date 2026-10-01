<?php

declare(strict_types=1);

use App\Actions\Alert\EvaluateAlertRules;
use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;

/**
 * @param  array<string, float|null>  $readings
 */
function sustainedReadings(Device $device, array $readings): DeviceMetric
{
    return collect([10, 5, 0])
        ->map(fn (int $minutesAgo): DeviceMetric => DeviceMetric::factory()->create([
            'device_id' => $device->id,
            'recorded_at' => now()->subMinutes($minutesAgo),
            ...$readings,
        ]))
        ->last();
}

it('triggers threshold alerts for the windows performance metrics', function (AlertMetric $metric, array $readings, float $threshold, float $expected): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->create([
        'metric' => $metric,
        'operator' => AlertOperator::GreaterThan,
        'threshold' => $threshold,
        'duration_minutes' => 5,
    ]);

    (new EvaluateAlertRules)($device, sustainedReadings($device, $readings));

    expect(Alert::sole())
        ->metric->toBe($metric)
        ->current_value->toBe($expected);
})->with([
    'cpu queue' => [AlertMetric::CpuQueue, ['cpu_queue_length' => 6.5], 4.0, 6.5],
    'disk busy' => [AlertMetric::DiskBusy, ['disk_busy_percent' => 97.25], 90.0, 97.25],
    'page file' => [AlertMetric::PageFile, ['swap_used_mib' => 15360.0, 'swap_total_mib' => 16384.0], 90.0, 93.8],
]);

it('stays quiet while the windows performance metrics are healthy', function (AlertMetric $metric, array $readings): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->create(['metric' => $metric, 'operator' => AlertOperator::GreaterThan, 'threshold' => 80, 'duration_minutes' => 5]);

    (new EvaluateAlertRules)($device, sustainedReadings($device, $readings));

    expect(Alert::count())->toBe(0);
})->with([
    'cpu queue' => [AlertMetric::CpuQueue, ['cpu_queue_length' => 0.265]],
    'disk busy' => [AlertMetric::DiskBusy, ['disk_busy_percent' => 0.81]],
    'page file' => [AlertMetric::PageFile, ['swap_used_mib' => 5474.98, 'swap_total_mib' => 17096.73]],
]);

it('skips devices that do not report the metric', function (AlertMetric $metric): void {
    $device = Device::factory()->active()->create();
    AlertRule::factory()->create(['metric' => $metric, 'operator' => AlertOperator::LessThan, 'threshold' => 50, 'duration_minutes' => 1]);

    (new EvaluateAlertRules)($device, sustainedReadings($device, []));

    expect(Alert::count())->toBe(0);
})->with([AlertMetric::CpuQueue, AlertMetric::DiskBusy, AlertMetric::PageFile]);

it('works out page file usage as a percentage of the page file', function (?float $used, ?float $total, ?float $percent): void {
    expect(DeviceMetric::factory()->make(['swap_used_mib' => $used, 'swap_total_mib' => $total])->pageFilePercent())->toBe($percent);
})->with([
    'real capture' => [5474.98, 17096.73, 32.0],
    'unknown' => [null, null, null],
    'no page file' => [0.0, 0.0, null],
]);

it('offers the new metrics when building alert rules', function (): void {
    expect(AlertMetric::thresholdBased())->toContain(AlertMetric::CpuQueue, AlertMetric::DiskBusy, AlertMetric::PageFile)
        ->and(AlertMetric::CpuQueue->label())->toBe('CPU Queue')
        ->and(AlertMetric::CpuQueue->unit())->toBe(' threads')
        ->and(AlertMetric::DiskBusy->label())->toBe('Disk Busy')
        ->and(AlertMetric::DiskBusy->unit())->toBe('%')
        ->and(AlertMetric::PageFile->label())->toBe('Page File Usage')
        ->and(AlertMetric::PageFile->unit())->toBe('%');
});
