<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('creates a pending device on first enrollment', function (): void {
    $payload = [
        'hostname' => 'WAREHOUSE-PC-07',
        'os' => 'Windows 11 Pro',
        'hardware_fingerprint' => 'CPU-UUID-1234',
    ];

    $response = $this->postJson('/api/enroll', $payload);

    $response->assertSuccessful()
        ->assertJson([
            'status' => Device::STATUS_PENDING,
        ])
        ->assertJsonMissing(['api_key']);

    $this->assertDatabaseHas('devices', [
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
        'status' => Device::STATUS_PENDING,
    ]);
});

it('returns api key when device is approved', function (): void {
    $device = Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
        'status' => Device::STATUS_ACTIVE,
        'api_key' => 'KEY-ABC',
    ]);

    $response = $this->postJson('/api/enroll', [
        'hostname' => 'WAREHOUSE-PC-07',
        'os' => 'Windows 11 Pro',
        'hardware_fingerprint' => 'CPU-UUID-1234',
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'status' => 'approved',
            'device_status' => Device::STATUS_ACTIVE,
            'api_key' => 'KEY-ABC',
        ]);
});

it('enroll merges by hostname when fingerprint arrives later', function (): void {
    // Device initially enrolled without fingerprint
    $device = Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-09',
        'hardware_fingerprint' => null,
        'status' => Device::STATUS_PENDING,
    ]);

    // Re-enroll with fingerprint present
    $response = $this->postJson('/api/enroll', [
        'hostname' => 'WAREHOUSE-PC-09',
        'hardware_fingerprint' => 'FP-LATE',
    ]);

    $response->assertSuccessful()->assertJsonStructure(['status']);

    $device->refresh();
    expect($device->hardware_fingerprint)->toBe('FP-LATE');
    // Ensure no duplicate device created
    expect(Device::where('hostname', 'WAREHOUSE-PC-09')->count())->toBe(1);
});
