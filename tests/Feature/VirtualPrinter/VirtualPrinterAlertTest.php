<?php

declare(strict_types=1);

use App\Actions\Device\RecordDevicePowerEvent;
use App\Actions\VirtualPrinter\SyncVirtualPrinterAlert;
use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Illuminate\Support\Facades\Notification;

/**
 * A plainly online print station whose Virtual Printer has been missing for the given minutes.
 */
function stationMissingFor(int $minutes, array $attributes = []): Device
{
    return Device::factory()->withApiKey('KEY-STATION')->create([
        'hostname' => 'LABEL-PC-1',
        'last_seen' => now(),
        'virtual_printer_seen_at' => now()->subMinutes($minutes + 1),
        'virtual_printer_missing_since' => now()->subMinutes($minutes),
        ...$attributes,
    ]);
}

function syncVirtualPrinterAlert(Device $device): void
{
    app(SyncVirtualPrinterAlert::class)($device->fresh());
}

function appsReport(bool $isRunning): array
{
    $instances = $isRunning ? ['Netdata_Agent', 'Virtual_Printer_2'] : ['Netdata_Agent'];
    $ids = fn (string $dimension, string $suffix): array => collect($instances)->map(fn (string $app): string => "{$dimension},app.{$app}{$suffix}@node")->all();

    return [
        'hostname' => 'LABEL-PC-1',
        'netdata_apps_cpu' => ['view' => ['dimensions' => ['ids' => $ids('user', '_cpu_utilization'), 'sts' => ['avg' => array_fill(0, count($instances), 0.5)]]]],
        'netdata_apps_mem' => ['view' => ['dimensions' => ['ids' => $ids('rss', '_mem_usage'), 'sts' => ['avg' => array_fill(0, count($instances), 50.0)]]]],
    ];
}

beforeEach(function (): void {
    $this->freezeSecond();
});

it('raises nothing before the station has been down for the alert minutes', function (): void {
    syncVirtualPrinterAlert(stationMissingFor(4));

    expect(Alert::query()->count())->toBe(0);
});

it('raises one alert from the built-in rule once the threshold passes', function (): void {
    $device = stationMissingFor(5);

    syncVirtualPrinterAlert($device);
    syncVirtualPrinterAlert($device);

    $alert = Alert::query()->sole();
    expect($alert->metric)->toBe(AlertMetric::VirtualPrinterDown)
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->severity)->toBe(AlertSeverity::Critical)
        ->and($alert->device_id)->toBe($device->id)
        ->and($alert->message)->toBe('LABEL-PC-1: Virtual Printer not running for 5m')
        ->and($alert->alertRule->is(AlertRule::virtualPrinterDown()))->toBeTrue();
});

it('notifies users like any other alert, at any hour', function (): void {
    Notification::fake();
    $this->travelTo(now()->setTime(3, 0));
    $user = User::factory()->create();

    syncVirtualPrinterAlert(stationMissingFor(6));

    Notification::assertSentTo($user, AlertTriggered::class);
});

it('raises the alert from a metrics report without the app', function (): void {
    stationMissingFor(6);

    $this->withHeaders(['X-Agent-Key' => 'KEY-STATION'])->postJson('/api/metrics', appsReport(isRunning: false))->assertSuccessful();

    expect(Alert::query()->sole()->metric)->toBe(AlertMetric::VirtualPrinterDown);
});

it('resolves the alert when a report sees the app again', function (): void {
    $device = stationMissingFor(6);
    syncVirtualPrinterAlert($device);

    $this->withHeaders(['X-Agent-Key' => 'KEY-STATION'])->postJson('/api/metrics', appsReport(isRunning: true))->assertSuccessful();

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved)
        ->and($device->fresh()->virtual_printer_missing_since)->toBeNull();
});

it('resolves the alert and restarts the clock when the station goes offline', function (): void {
    $device = stationMissingFor(30);
    syncVirtualPrinterAlert($device);
    $device->update(['last_seen' => now()->subSeconds(60)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved)
        ->and($device->fresh()->virtual_printer_missing_since)->toBeNull();
});

it('resolves the alert and restarts the clock when the station powers off or on', function (DevicePowerState $powerState, PowerEventReason $reason): void {
    $device = stationMissingFor(30);
    syncVirtualPrinterAlert($device);

    app(RecordDevicePowerEvent::class)($device->fresh(), $powerState, $reason);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved)
        ->and($device->fresh()->virtual_printer_missing_since)->toBeNull();
})->with([
    'shutting down' => [DevicePowerState::PoweringOff, PowerEventReason::Shutdown],
    'sleeping' => [DevicePowerState::PoweringOff, PowerEventReason::Sleep],
    'booting' => [DevicePowerState::PoweringOn, PowerEventReason::Boot],
]);

it('only counts time spent online towards the alert', function (): void {
    $device = stationMissingFor(30, ['last_seen' => now()->subSeconds(60)]);
    $this->artisan('devices:check-offline')->assertSuccessful();

    $device->update(['last_seen' => now()]);
    $this->withHeaders(['X-Agent-Key' => 'KEY-STATION'])->postJson('/api/metrics', appsReport(isRunning: false))->assertSuccessful();

    expect(Alert::query()->count())->toBe(0)
        ->and($device->fresh()->virtual_printer_missing_since)->toEqual(now());
});

it('raises nothing for a station that is not plainly online', function (Closure $attributes): void {
    syncVirtualPrinterAlert(stationMissingFor(30, $attributes()));

    expect(Alert::query()->count())->toBe(0);
})->with([
    // Closures so the timestamps are taken when each case runs, not when the suite loads.
    'offline' => [fn (): array => ['last_seen' => now()->subHour()]],
    'powering off' => [fn (): array => ['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]],
    'powering on' => [fn (): array => ['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()]],
]);

it('takes the alert minutes from config', function (): void {
    config(['devices.watched_apps.virtual_printer.alert_after_minutes' => 15]);

    syncVirtualPrinterAlert(stationMissingFor(10));

    expect(Alert::query()->count())->toBe(0);
});

it('raises nothing while the built-in rule is switched off', function (): void {
    AlertRule::virtualPrinterDown()->update(['is_active' => false]);

    syncVirtualPrinterAlert(stationMissingFor(30));

    expect(Alert::query()->count())->toBe(0);
});
