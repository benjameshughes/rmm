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

it('accepts v3 format with extended metrics', function (): void {
    $device = Device::factory()->active()->create([
        'api_key' => 'VALID-KEY-123',
        'os_name' => null,
    ]);

    $response = $this->withHeaders(['X-Agent-Key' => 'VALID-KEY-123'])
        ->postJson('/api/metrics', [
            'hostname' => 'test-device',
            'timestamp' => '2025-12-08T10:00:00Z',
            'agent_version' => '0.2.0',
            'system_info' => [
                'netdata_version' => '1.44.0',
                'os_name' => 'Windows',
                'os_version' => '10.0.19045',
                'architecture' => 'x86_64',
            ],
            'cpu' => [
                'usage_percent' => 25.5,
                'user' => 15.0,
                'system' => 10.0,
                'idle' => 74.5,
            ],
            'memory' => [
                'usage_percent' => 65.0,
                'used_mib' => 8192.0,
                'free_mib' => 2048.0,
                'total_mib' => 16384.0,
            ],
            'load' => [
                'load1' => 1.5,
                'load5' => 1.2,
                'load15' => 0.8,
            ],
            'uptime' => [
                'seconds' => 86400.0,
            ],
            'alerts' => [
                'normal' => 10,
                'warning' => 2,
                'critical' => 0,
            ],
        ]);

    $response->assertSuccessful();

    $metric = DeviceMetric::where('device_id', $device->id)->first();
    expect($metric->cpu)->toBe(25.5);
    expect($metric->ram)->toBe(65.0);
    expect($metric->load1)->toBe(1.5);
    expect($metric->load5)->toBe(1.2);
    expect($metric->load15)->toBe(0.8);
    expect($metric->uptime_seconds)->toBe(86400);
    expect($metric->memory_used_mib)->toBe(8192.0);
    expect($metric->memory_total_mib)->toBe(16384.0);
    expect($metric->alerts_normal)->toBe(10);
    expect($metric->alerts_warning)->toBe(2);
    expect($metric->alerts_critical)->toBe(0);
    expect($metric->agent_version)->toBe('0.2.0');

    // Device should be updated with OS info
    $device->refresh();
    expect($device->os_name)->toBe('Windows');
    expect($device->os_version)->toBe('10.0.19045');
});
