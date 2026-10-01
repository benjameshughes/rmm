<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('returns pending when device not found', function (): void {
    $response = $this->getJson('/api/check?hostname=UNKNOWN-PC');

    $response->assertSuccessful()
        ->assertExactJson([
            'status' => DeviceStatus::Pending->value,
        ]);
});

it('delivers a freshly issued key once via exact fingerprint (GET)', function (): void {
    $device = Device::factory()->awaitingKeyClaim('KEY-ABC')->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'hardware_fingerprint' => 'CPU-UUID-1234',
    ]);

    $this->getJson('/api/check?hardware_fingerprint=CPU-UUID-1234')
        ->assertSuccessful()
        ->assertExactJson([
            'status' => 'approved',
            'device_status' => DeviceStatus::Active->value,
            'api_key' => 'KEY-ABC',
        ]);

    $device->refresh();
    expect($device->api_key_claimed_at)->not->toBeNull()
        ->and($device->pending_api_key)->toBeNull()
        ->and($device->api_key_hash)->toBe(Device::hashApiKey('KEY-ABC'));
});

it('never delivers the key a second time', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-ONCE')->create([
        'hostname' => 'ONCE-PC',
        'hardware_fingerprint' => 'FP-ONCE',
    ]);

    $payload = ['hostname' => 'ONCE-PC', 'hardware_fingerprint' => 'FP-ONCE'];

    $first = $this->postJson('/api/check', $payload)->assertSuccessful();
    $second = $this->postJson('/api/check', $payload)->assertSuccessful();

    expect($first->json('api_key'))->toBe('KEY-ONCE');
    $second->assertExactJson([
        'status' => 'approved',
        'device_status' => DeviceStatus::Active->value,
    ]);
});

it('cannot claim a key from a stale model once another request has claimed it', function (): void {
    $device = Device::factory()->awaitingKeyClaim('KEY-RACE')->create([
        'hardware_fingerprint' => 'FP-RACE',
    ]);

    $racerA = Device::find($device->id);
    $racerB = Device::find($device->id);

    expect($racerA->claimPendingApiKey())->toBe('KEY-RACE')
        ->and($racerB->claimPendingApiKey())->toBeNull();
});

it('does not hand out a key that was replaced after it was read', function (): void {
    $device = Device::factory()->awaitingKeyClaim('KEY-OLD')->create([
        'hardware_fingerprint' => 'FP-REISSUE',
    ]);

    $stale = Device::find($device->id);
    $device->resetEnrolment();
    $device->issueApiKey();

    expect($stale->claimPendingApiKey())->toBeNull()
        ->and($device->fresh()->api_key_claimed_at)->toBeNull();
});

it('claimed key authenticates against the heartbeat endpoint', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-WORKS')->create([
        'hardware_fingerprint' => 'FP-WORKS',
    ]);

    $apiKey = $this->postJson('/api/check', ['hardware_fingerprint' => 'FP-WORKS'])
        ->assertSuccessful()
        ->json('api_key');

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => $apiKey])
        ->assertSuccessful();
});

it('returns approved without a key when the key has already been claimed', function (): void {
    Device::factory()->withApiKey('KEY-CLAIMED')->create([
        'hardware_fingerprint' => 'FP-CLAIMED',
    ]);

    $this->postJson('/api/check', ['hardware_fingerprint' => 'FP-CLAIMED'])
        ->assertSuccessful()
        ->assertExactJson([
            'status' => 'approved',
            'device_status' => DeviceStatus::Active->value,
        ]);
});

it('returns pending for a pending device', function (): void {
    Device::factory()->create([
        'hostname' => 'WAREHOUSE-PC-08',
        'hardware_fingerprint' => 'FP-PENDING',
        'status' => DeviceStatus::Pending,
    ]);

    $this->postJson('/api/check', [
        'hostname' => 'WAREHOUSE-PC-08',
        'hardware_fingerprint' => 'FP-PENDING',
    ])
        ->assertSuccessful()
        ->assertExactJson([
            'status' => DeviceStatus::Pending->value,
            'device_status' => DeviceStatus::Pending->value,
        ]);
});

it('returns revoked for a revoked device', function (): void {
    Device::factory()->create([
        'hardware_fingerprint' => 'FP-REVOKED',
        'status' => DeviceStatus::Revoked,
    ]);

    $this->postJson('/api/check', ['hardware_fingerprint' => 'FP-REVOKED'])
        ->assertSuccessful()
        ->assertExactJson([
            'status' => DeviceStatus::Revoked->value,
            'device_status' => DeviceStatus::Revoked->value,
        ]);
});

it('does not reveal anything when only the hostname of an active device is sent', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-HOSTNAME')->create([
        'hostname' => 'HOST-ONLY',
        'hardware_fingerprint' => 'FP-REAL',
    ]);

    $this->getJson('/api/check?hostname=HOST-ONLY')
        ->assertSuccessful()
        ->assertExactJson(['status' => DeviceStatus::Pending->value]);

    expect(Device::firstWhere('hostname', 'HOST-ONLY')->api_key_claimed_at)->toBeNull();
});

it('does not fall back to hostname when the fingerprint does not match', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-XYZ')->create([
        'hostname' => 'HOST-MATCH',
        'hardware_fingerprint' => 'FP-REAL',
    ]);

    $this->getJson('/api/check?hardware_fingerprint=FP-WRONG&hostname=HOST-MATCH')
        ->assertSuccessful()
        ->assertExactJson(['status' => DeviceStatus::Pending->value]);

    expect(Device::firstWhere('hostname', 'HOST-MATCH')->pending_api_key)->toBe('KEY-XYZ');
});

it('does not match a device without a fingerprint by hostname', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-NOFP')->create([
        'hostname' => 'NO-FP-HOST',
        'hardware_fingerprint' => null,
    ]);

    $this->getJson('/api/check?hardware_fingerprint=FP-123&hostname=NO-FP-HOST')
        ->assertSuccessful()
        ->assertExactJson(['status' => DeviceStatus::Pending->value]);
});
