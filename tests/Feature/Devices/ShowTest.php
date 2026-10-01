<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Livewire\Devices\Pending;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('shows device details and recent metrics', function (): void {
    $user = User::factory()->create();

    $device = Device::factory()->active()->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'last_seen' => now(),
        'os' => 'Windows 11 Pro',
    ]);

    DeviceMetric::factory()->count(3)->create([
        'device_id' => $device->id,
        'cpu' => 22.22,
        'ram' => 44.44,
        'recorded_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('devices.show', $device))
        ->assertSuccessful()
        ->assertSee('WAREHOUSE-PC-07')
        ->assertSee('22.2%')
        ->assertSee('44.4%');
});

it('shows key status instead of any part of the key', function (): void {
    $user = User::factory()->create();

    $claimed = Device::factory()->withApiKey('KEY-SHOW-CLAIMED')->create();
    $awaiting = Device::factory()->awaitingKeyClaim('KEY-SHOW-AWAITING')->create();
    $pending = Device::factory()->create();

    $this->actingAs($user)
        ->get(route('devices.show', $claimed))
        ->assertSuccessful()
        ->assertSee('Claimed')
        ->assertDontSee('KEY-SHOW')
        ->assertDontSee(substr(Device::hashApiKey('KEY-SHOW-CLAIMED'), 0, 8));

    $this->actingAs($user)
        ->get(route('devices.show', $awaiting))
        ->assertSuccessful()
        ->assertSee('Awaiting agent')
        ->assertDontSee('KEY-SHOW');

    $this->actingAs($user)
        ->get(route('devices.show', $pending))
        ->assertSuccessful()
        ->assertSee('None')
        ->assertDontSee('Reset enrolment');
});

it('resets enrolment so the old key stops working', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('KEY-TO-RESET')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-TO-RESET'])->assertSuccessful();

    Livewire::actingAs($user)
        ->test(Show::class, ['device' => $device])
        ->assertSee('Reset enrolment')
        ->call('resetEnrolment')
        ->assertDispatched('notify');

    $device->refresh();
    expect($device->status)->toBe(DeviceStatus::Pending)
        ->and($device->api_key_hash)->toBeNull()
        ->and($device->pending_api_key)->toBeNull()
        ->and($device->api_key_issued_at)->toBeNull()
        ->and($device->api_key_claimed_at)->toBeNull();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-TO-RESET'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid or revoked device key.']);
});

it('lets a reset device re-enrol and collect a fresh key after re-approval', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('KEY-BEFORE-RESET')->create([
        'hostname' => 'REENROL-PC',
        'hardware_fingerprint' => 'FP-REENROL',
    ]);

    Livewire::actingAs($user)->test(Show::class, ['device' => $device])->call('resetEnrolment');

    $payload = ['hostname' => 'REENROL-PC', 'hardware_fingerprint' => 'FP-REENROL'];
    $this->postJson('/api/enroll', $payload)->assertSuccessful()->assertJsonPath('status', 'pending');
    $this->postJson('/api/check', $payload)->assertJsonMissingPath('api_key');

    Livewire::actingAs($user)->test(Pending::class)->call('approve', $device->id);

    $newKey = $this->postJson('/api/check', $payload)->assertSuccessful()->json('api_key');

    expect($newKey)->toBeString()->not->toBe('KEY-BEFORE-RESET');
    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => $newKey])->assertSuccessful();
});
