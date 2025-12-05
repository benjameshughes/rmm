<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('rejects metrics without device key', function (): void {
    $response = $this->postJson('/api/metrics', [
        'cpu' => 10.5,
        'ram' => 20.1,
    ]);

    $response->assertUnauthorized();
});

it('rejects metrics with invalid device key', function (): void {
    $response = $this->withHeaders(['X-Device-Key' => 'INVALID'])
        ->postJson('/api/metrics', [
            'cpu' => 10.5,
            'ram' => 20.1,
        ]);

    $response->assertUnauthorized();
});

it('accepts metrics and updates last_seen', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
        'last_seen' => null,
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => 55.25,
            'ram' => 71.5,
            'payload' => [
                'disks' => [
                    ['name' => 'C:', 'free' => 120_000_000_000],
                ],
            ],
        ]);

    $response->assertSuccessful();

    $device->refresh();
    expect($device->last_seen)->not->toBeNull();

    expect(DeviceMetric::where('device_id', $device->id)->count())->toBe(1);
});

it('accepts metrics with netdata cpu json format', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $cpuJson = json_encode([
        'labels' => ['time', 'user', 'system', 'idle'],
        'data' => [[1733430000, 12.0, 3.0, 85.0]],
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => $cpuJson,
            'ram' => 50.0,
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->cpu)->toBe(15.0); // 100 - 85
});

it('accepts metrics with netdata ram json format', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $ramJson = json_encode([
        'labels' => ['time', 'used', 'free', 'cached', 'buffers'],
        'data' => [[1733430000, 4096, 12288, 2048, 512]],
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => 25.0,
            'ram' => $ramJson,
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->ram)->toBe(21.62); // 4096 / 18944 * 100
});

it('accepts metrics with jsonwrap format', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $cpuJson = json_encode([
        'result' => [
            'labels' => ['time', 'user', 'system', 'idle'],
            'data' => [[1733430000, 10.0, 5.0, 85.0]],
        ],
    ]);

    $ramJson = json_encode([
        'result' => [
            'labels' => ['time', 'used', 'free'],
            'data' => [[1733430000, 8192, 8192]],
        ],
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => $cpuJson,
            'ram' => $ramJson,
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->cpu)->toBe(15.0); // 100 - 85
    expect($metric->ram)->toBe(50.0); // 8192 / 16384 * 100
});

it('stores null when cpu parsing fails', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => 'invalid json {',
            'ram' => 50.0,
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->cpu)->toBeNull();
    expect($metric->ram)->toBe(50.0);
});

it('stores null when ram parsing fails', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
    ]);

    $response = $this->withHeaders(['X-Device-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'cpu' => 25.0,
            'ram' => 'invalid json {',
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->cpu)->toBe(25.0);
    expect($metric->ram)->toBeNull();
});
