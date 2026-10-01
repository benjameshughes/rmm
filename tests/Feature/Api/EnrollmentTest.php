<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
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
        ->assertExactJson([
            'status' => DeviceStatus::Pending->value,
            'device_status' => DeviceStatus::Pending->value,
        ]);

    $this->assertDatabaseHas('devices', [
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
        'status' => DeviceStatus::Pending->value,
        'api_key_hash' => null,
    ]);
});

it('never returns an api key for an approved device', function (string $state): void {
    Device::factory()->{$state}('KEY-ABC')->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
    ]);

    $response = $this->postJson('/api/enroll', [
        'hostname' => 'WAREHOUSE-PC-07',
        'os' => 'Windows 11 Pro',
        'hardware_fingerprint' => 'CPU-UUID-1234',
    ]);

    $response->assertSuccessful()
        ->assertExactJson([
            'status' => 'approved',
            'device_status' => DeviceStatus::Active->value,
        ]);

    expect($response->getContent())->not->toContain('KEY-ABC');
})->with(['withApiKey', 'awaitingKeyClaim']);

it('does not modify an active device when it re-enrolls', function (): void {
    $device = Device::factory()->withApiKey('KEY-ACTIVE')->create([
        'hostname' => 'REAL-PC',
        'hardware_fingerprint' => 'FP-REAL',
        'os' => 'Windows 11 Pro',
        'cpu_model' => 'Real CPU',
        'last_ip' => '10.0.0.5',
    ]);
    $before = $device->fresh()->getAttributes();

    $this->postJson('/api/enroll', [
        'hostname' => 'EVIL-PC',
        'hardware_fingerprint' => 'FP-REAL',
        'os' => 'Evil OS',
        'cpu_model' => 'Evil CPU',
    ])->assertSuccessful();

    expect($device->fresh()->getAttributes())->toBe($before)
        ->and(Device::count())->toBe(1);
});

it('creates a new pending device when a known hostname arrives with a new fingerprint', function (): void {
    $original = Device::factory()->withApiKey('KEY-ORIGINAL')->create([
        'hostname' => 'WAREHOUSE-PC-09',
        'hardware_fingerprint' => 'FP-ORIGINAL',
    ]);

    $this->postJson('/api/enroll', [
        'hostname' => 'WAREHOUSE-PC-09',
        'hardware_fingerprint' => 'FP-IMPOSTER',
    ])
        ->assertSuccessful()
        ->assertExactJson([
            'status' => DeviceStatus::Pending->value,
            'device_status' => DeviceStatus::Pending->value,
        ]);

    expect(Device::where('hostname', 'WAREHOUSE-PC-09')->count())->toBe(2)
        ->and($original->fresh()->hardware_fingerprint)->toBe('FP-ORIGINAL')
        ->and($original->fresh()->status)->toBe(DeviceStatus::Active);

    $this->assertDatabaseHas('devices', [
        'hostname' => 'WAREHOUSE-PC-09',
        'hardware_fingerprint' => 'FP-IMPOSTER',
        'status' => DeviceStatus::Pending->value,
    ]);
});

it('does not attach a fingerprint to a device matched only by hostname', function (): void {
    $device = Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-10',
        'hardware_fingerprint' => null,
        'status' => DeviceStatus::Pending,
    ]);

    $this->postJson('/api/enroll', [
        'hostname' => 'WAREHOUSE-PC-10',
        'hardware_fingerprint' => 'FP-LATE',
    ])->assertSuccessful();

    expect($device->fresh()->hardware_fingerprint)->toBeNull()
        ->and(Device::where('hostname', 'WAREHOUSE-PC-10')->count())->toBe(2);
});

it('returns 403 revoked for a revoked device without modifying it', function (): void {
    $device = Device::factory()->create([
        'hostname' => 'REVOKED-PC',
        'hardware_fingerprint' => 'FP-REVOKED',
        'status' => DeviceStatus::Revoked,
        'os' => 'Windows 10',
    ]);

    $response = $this->postJson('/api/enroll', [
        'hostname' => 'REVOKED-PC',
        'hardware_fingerprint' => 'FP-REVOKED',
        'os' => 'Windows 11',
    ]);

    $response->assertForbidden()
        ->assertJson([
            'status' => DeviceStatus::Revoked->value,
            'device_status' => DeviceStatus::Revoked->value,
        ]);

    expect(mb_strtolower($response->getContent()))->toContain('revoked')
        ->and($response->json('api_key'))->toBeNull()
        ->and($device->fresh()->os)->toBe('Windows 10')
        ->and($device->fresh()->status)->toBe(DeviceStatus::Revoked);
});

it('refreshes descriptive fields for a pending device but not its identity', function (): void {
    $device = Device::factory()->create([
        'hostname' => 'PENDING-PC',
        'hardware_fingerprint' => 'FP-PENDING',
        'status' => DeviceStatus::Pending,
        'os' => 'Windows 10',
        'cpu_model' => 'Old CPU',
        'cpu_cores' => 4,
        'last_ip' => '10.0.0.1',
    ]);

    $this->postJson('/api/enroll', [
        'hostname' => 'RENAMED-PC',
        'hardware_fingerprint' => 'FP-PENDING',
        'os' => 'Windows 11',
        'cpu_model' => 'New CPU',
    ])->assertSuccessful();

    $device->refresh();
    expect($device->os)->toBe('Windows 11')
        ->and($device->cpu_model)->toBe('New CPU')
        ->and($device->cpu_cores)->toBe(4)
        ->and($device->last_ip)->toBe('127.0.0.1')
        ->and($device->hostname)->toBe('PENDING-PC')
        ->and($device->status)->toBe(DeviceStatus::Pending)
        ->and($device->api_key_hash)->toBeNull();
});

it('rejects enrollment without a hardware fingerprint', function (): void {
    $this->postJson('/api/enroll', ['hostname' => 'NO-FINGERPRINT-PC'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hardware_fingerprint');

    expect(Device::query()->where('hostname', 'NO-FINGERPRINT-PC')->exists())->toBeFalse();
});
