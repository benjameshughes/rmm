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

