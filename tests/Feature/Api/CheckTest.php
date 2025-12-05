<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('returns pending when device not found', function (): void {
    $response = $this->getJson('/api/check?hostname=UNKNOWN-PC');

    $response->assertSuccessful()
        ->assertJson([
            'status' => Device::STATUS_PENDING,
        ]);
});

it('returns status and api key for approved device (GET)', function (): void {
    Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
        'status' => Device::STATUS_ACTIVE,
        'api_key' => 'KEY-ABC',
    ]);

    $response = $this->getJson('/api/check?hardware_fingerprint=CPU-UUID-1234');

    $response->assertSuccessful()
        ->assertJson([
            'status' => 'approved',
            'device_status' => Device::STATUS_ACTIVE,
            'api_key' => 'KEY-ABC',
        ]);
});

it('returns status via POST body too', function (): void {
    Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-08',
        'status' => Device::STATUS_PENDING,
    ]);

    $response = $this->postJson('/api/check', [
        'hostname' => 'WAREHOUSE-PC-08',
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'status' => Device::STATUS_PENDING,
        ]);
});

it('falls back to hostname if fingerprint not yet attached', function (): void {
    $device = Device::factory()->create([
        'hostname' => 'HOST-ONLY',
        'hardware_fingerprint' => null,
        'status' => Device::STATUS_ACTIVE,
        'api_key' => 'KEY-XYZ',
    ]);

    $response = $this->getJson('/api/check?hardware_fingerprint=FP-123&hostname=HOST-ONLY');

    $response->assertSuccessful()
        ->assertJson([
            'status' => 'approved',
            'device_status' => Device::STATUS_ACTIVE,
            'api_key' => 'KEY-XYZ',
        ]);
});
