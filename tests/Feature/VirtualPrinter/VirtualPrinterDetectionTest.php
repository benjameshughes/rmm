<?php

declare(strict_types=1);

use App\Enums\VirtualPrinterState;
use App\Models\Device;

const VIRTUAL_PRINTER_NODE = '5fb86e46-890a-af4f-afde-034c792a2a78';

/**
 * A Netdata apps response grouped by instance and dimension, the way the agent sends `netdata_apps_*`.
 *
 * @param  array<string, array<string, float>>  $apps  app => [dimension => value]
 */
function appsResponse(array $apps, string $suffix): array
{
    $dimensions = collect($apps)->flatMap(fn (array $values, string $app): array => collect($values)
        ->mapWithKeys(fn (float $value, string $dimension): array => ["{$dimension},app.{$app}{$suffix}@".VIRTUAL_PRINTER_NODE => $value])
        ->all());

    return ['view' => ['dimensions' => ['ids' => $dimensions->keys()->all(), 'sts' => ['avg' => $dimensions->values()->all()]]]];
}

/**
 * One raw Netdata metrics report. The Netdata agent always runs; the Virtual Printer only when given.
 */
function virtualPrinterReport(?float $cpu = null, ?float $memoryMib = null): array
{
    $cpuApps = ['Netdata_Agent' => ['user' => 1.2, 'system' => 0.9]];
    $memoryApps = ['Netdata_Agent' => ['rss' => 106.8]];

    if ($cpu !== null) {
        $cpuApps['Virtual_Printer_2'] = ['user' => $cpu, 'system' => 0.0];
    }

    if ($memoryMib !== null) {
        $memoryApps['Virtual_Printer_2'] = ['rss' => $memoryMib];
    }

    return [
        'hostname' => 'LABEL-PC-1',
        'agent_version' => '0.8.0',
        'netdata_apps_cpu' => appsResponse($cpuApps, '_cpu_utilization'),
        'netdata_apps_mem' => appsResponse($memoryApps, '_mem_usage'),
    ];
}

function sendVirtualPrinterReport(array $report): void
{
    test()->withHeaders(['X-Agent-Key' => 'KEY-LABEL'])->postJson('/api/metrics', $report)->assertSuccessful();
}

beforeEach(function (): void {
    $this->freezeSecond();
});

function labelPc(array $attributes = []): Device
{
    return Device::factory()->withApiKey('KEY-LABEL')->create(['last_seen' => now(), ...$attributes]);
}

it('stamps when the virtual printer was seen and clears the missing clock', function (): void {
    $device = labelPc(['virtual_printer_seen_at' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()]);

    sendVirtualPrinterReport(virtualPrinterReport(cpu: 0.4, memoryMib: 52.3));

    expect($device->fresh())
        ->virtual_printer_seen_at->toEqual(now())
        ->virtual_printer_missing_since->toBeNull();
});

it('counts an idle virtual printer at 0% cpu as running', function (): void {
    $device = labelPc();

    sendVirtualPrinterReport(virtualPrinterReport(cpu: 0.0, memoryMib: 48.0));

    expect($device->fresh()->virtual_printer_seen_at)->toEqual(now());
});

it('does not count a closed app whose dimensions linger zero-filled', function (): void {
    $device = labelPc();

    sendVirtualPrinterReport(virtualPrinterReport(cpu: 0.0, memoryMib: 0.0));

    expect($device->fresh()->virtual_printer_seen_at)->toBeNull();
});

it('leaves a pc that never ran the virtual printer alone', function (): void {
    $device = labelPc();

    sendVirtualPrinterReport(virtualPrinterReport());

    expect($device->fresh())
        ->virtual_printer_seen_at->toBeNull()
        ->virtual_printer_missing_since->toBeNull()
        ->virtualPrinterState()->toBe(VirtualPrinterState::Unwatched);
});

it('starts the missing clock once when a print station reports without it', function (): void {
    $device = labelPc(['virtual_printer_seen_at' => now()->subHour()]);

    sendVirtualPrinterReport(virtualPrinterReport());
    $startedAt = $device->fresh()->virtual_printer_missing_since;
    $this->travel(30)->seconds();
    sendVirtualPrinterReport(virtualPrinterReport());

    expect($startedAt)->not->toBeNull()
        ->and($device->fresh()->virtual_printer_missing_since->equalTo($startedAt))->toBeTrue();
});

it('starts no clock while the pc is still powering on', function (): void {
    $device = Device::factory()->withApiKey('KEY-LABEL')->poweringOn()->create(['virtual_printer_seen_at' => now()->subHour()]);

    sendVirtualPrinterReport(virtualPrinterReport());

    expect($device->fresh()->virtual_printer_missing_since)->toBeNull();
});

it('reads the watched app name from config', function (): void {
    config(['devices.watched_apps.virtual_printer.netdata_app' => 'Netdata_Agent']);
    $device = labelPc();

    sendVirtualPrinterReport(virtualPrinterReport());

    expect($device->fresh()->virtual_printer_seen_at)->toEqual(now());
});

it('works out the state from when the app was seen and how long it has been missing', function (Closure $attributes, VirtualPrinterState $state): void {
    $device = Device::factory()->withApiKey()->create($attributes());

    expect($device->virtualPrinterState())->toBe($state);
})->with([
    // Closures so the timestamps are taken when each case runs, not when the suite loads.
    'running' => [fn (): array => ['last_seen' => now(), 'virtual_printer_seen_at' => now()], VirtualPrinterState::Ready],
    'missing within the grace' => [fn (): array => ['last_seen' => now(), 'virtual_printer_seen_at' => now()->subMinutes(3), 'virtual_printer_missing_since' => now()->subMinute()], VirtualPrinterState::Ready],
    'missing past the grace' => [fn (): array => ['last_seen' => now(), 'virtual_printer_seen_at' => now()->subHours(14), 'virtual_printer_missing_since' => now()->subMinutes(2)], VirtualPrinterState::Down],
    'never ran it' => [fn (): array => ['last_seen' => now()], VirtualPrinterState::Unwatched],
    'last ran it before the lookback' => [fn (): array => ['last_seen' => now(), 'virtual_printer_seen_at' => now()->subDays(15), 'virtual_printer_missing_since' => now()->subDay()], VirtualPrinterState::Unwatched],
    'offline' => [fn (): array => ['last_seen' => now()->subMinutes(5), 'virtual_printer_seen_at' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()], VirtualPrinterState::Unwatched],
    'powering off' => [fn (): array => ['last_seen' => now(), 'power_state' => 'powering_off', 'power_state_changed_at' => now(), 'virtual_printer_seen_at' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()], VirtualPrinterState::Unwatched],
    'powering on' => [fn (): array => ['last_seen' => now(), 'power_state' => 'powering_on', 'power_state_changed_at' => now(), 'virtual_printer_seen_at' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()], VirtualPrinterState::Unwatched],
]);

it('takes the grace and lookback from config', function (): void {
    config(['devices.watched_apps.virtual_printer.down_after_minutes' => 10, 'devices.watched_apps.virtual_printer.station_lookback_days' => 1]);
    $device = Device::factory()->withApiKey()->create(['last_seen' => now(), 'virtual_printer_seen_at' => now()->subHours(12), 'virtual_printer_missing_since' => now()->subMinutes(5)]);

    expect($device->virtualPrinterState())->toBe(VirtualPrinterState::Ready);

    $device->virtual_printer_seen_at = now()->subDays(2);

    expect($device->virtualPrinterState())->toBe(VirtualPrinterState::Unwatched);
});

it('says how long a station has been down', function (): void {
    $device = Device::factory()->withApiKey()->create(['last_seen' => now(), 'virtual_printer_seen_at' => now()->subDay(), 'virtual_printer_missing_since' => now()->subHours(14)]);

    expect($device->virtualPrinterLabel())->toBe('Down for 14h');
});
