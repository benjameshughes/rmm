<?php

declare(strict_types=1);

use App\Actions\Device\RecordDevicePowerEvent;
use App\Actions\Printer\SyncPrinterAlerts;
use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Events\MetricsReceived;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\DevicePrinter;

function alertingPc(array $attributes = []): Device
{
    return Device::factory()->windows()->withApiKey()->create([
        'hostname' => 'LABEL-PC-1',
        'last_seen' => now(),
        'printers_reported_at' => now(),
        ...$attributes,
    ]);
}

function syncPrinterAlerts(Device $device): void
{
    app(SyncPrinterAlerts::class)($device->fresh());
}

beforeEach(function (): void {
    $this->freezeSecond();
});

it('raises nothing before a printer has been a problem for the alert minutes', function (): void {
    $device = alertingPc();
    DevicePrinter::factory()->offline(forMinutes: 4)->for($device)->create(['name' => 'Zebra']);

    syncPrinterAlerts($device);

    expect(Alert::query()->count())->toBe(0);
});

it('raises one alert per printer from the built-in rule, without duplicates', function (): void {
    $device = alertingPc();
    DevicePrinter::factory()->offline(forMinutes: 5)->for($device)->create(['name' => 'Zebra GK420d - ZPL']);
    DevicePrinter::factory()->offline(forMinutes: 9)->for($device)->create(['name' => 'Zebra GK420d - ZPL #2']);

    syncPrinterAlerts($device);
    syncPrinterAlerts($device);

    $alerts = Alert::query()->orderBy('subject')->get();
    expect($alerts)->toHaveCount(2)
        ->and($alerts->pluck('subject')->all())->toBe(['Zebra GK420d - ZPL', 'Zebra GK420d - ZPL #2'])
        ->and($alerts->first()->metric)->toBe(AlertMetric::PrinterProblem)
        ->and($alerts->first()->status)->toBe(AlertStatus::Triggered)
        ->and($alerts->first()->severity)->toBe(AlertSeverity::Critical)
        ->and($alerts->first()->message)->toBe('LABEL-PC-1: Zebra GK420d - ZPL: Offline')
        ->and($alerts->first()->alertRule->is(AlertRule::printerProblem()))->toBeTrue();
});

it('keeps the message current and resolves the alert once the printer is fine', function (): void {
    $device = alertingPc();
    $printer = DevicePrinter::factory()->offline(forMinutes: 6)->for($device)->create(['name' => 'Zebra']);
    syncPrinterAlerts($device);

    $printer->forceFill(['snapshot' => ['name' => 'Zebra', 'status' => 0x88, 'jobs_count' => 0, 'jobs' => []]])->save();
    syncPrinterAlerts($device);
    expect(Alert::query()->sole()->message)->toBe('LABEL-PC-1: Zebra: Paper jam, Offline');

    $printer->forceFill(['problem_since' => null])->save();
    syncPrinterAlerts($device);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('resolves printer and spooler alerts when the PC stops being plainly online', function (): void {
    $device = alertingPc(['spooler_down_since' => now()->subMinutes(10)]);
    DevicePrinter::factory()->offline(forMinutes: 10)->for($device)->create(['name' => 'Zebra']);
    syncPrinterAlerts($device);
    expect(Alert::query()->unresolved()->count())->toBe(2);

    app(RecordDevicePowerEvent::class)($device->fresh(), DevicePowerState::PoweringOff, PowerEventReason::Shutdown);

    expect(Alert::query()->unresolved()->count())->toBe(0);
});

it('resolves a printer alert once the printer reports go stale, as metrics arrive', function (): void {
    $device = alertingPc();
    DevicePrinter::factory()->offline(forMinutes: 10)->for($device)->create(['name' => 'Zebra']);
    syncPrinterAlerts($device);

    $device->forceFill(['printers_reported_at' => now()->subMinutes(config('printers.stale_after_minutes') + 1)])->save();
    MetricsReceived::dispatch($device->fresh(), DeviceMetric::factory()->for($device)->create());

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('raises nothing while the built-in rule is switched off', function (): void {
    AlertRule::printerProblem()->update(['is_active' => false]);
    $device = alertingPc();
    DevicePrinter::factory()->offline(forMinutes: 30)->for($device)->create(['name' => 'Zebra']);

    syncPrinterAlerts($device);

    expect(Alert::query()->count())->toBe(0);
});

it('raises its own alert for a print spooler down the alert minutes, and resolves it when back', function (): void {
    $device = alertingPc(['spooler_down_since' => now()->subMinutes(4)]);
    syncPrinterAlerts($device);
    expect(Alert::query()->count())->toBe(0);

    $device->forceFill(['spooler_down_since' => now()->subMinutes(6)])->save();
    syncPrinterAlerts($device);
    syncPrinterAlerts($device);

    $alert = Alert::query()->sole();
    expect($alert->metric)->toBe(AlertMetric::SpoolerDown)
        ->and($alert->message)->toBe('LABEL-PC-1: print spooler not running for 6m')
        ->and($alert->alertRule->is(AlertRule::spoolerDown()))->toBeTrue();

    $device->forceFill(['spooler_down_since' => null])->save();
    syncPrinterAlerts($device);

    expect($alert->fresh()->status)->toBe(AlertStatus::Resolved);
});

it('keeps printer alerts out of the user-built threshold rules', function (): void {
    expect(AlertMetric::thresholdBased())->not->toContain(AlertMetric::PrinterProblem)
        ->and(AlertMetric::thresholdBased())->not->toContain(AlertMetric::SpoolerDown);
});
