<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Livewire\Devices\Pending;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('shows pending devices and allows approve/reject', function (): void {
    $user = User::factory()->create();
    $d1 = Device::factory()->create(['hostname' => 'PEND-1']);
    $d2 = Device::factory()->create(['hostname' => 'PEND-2']);

    $this->actingAs($user)
        ->get('/devices/pending')
        ->assertSuccessful()
        ->assertSee('PEND-1')
        ->assertSee('PEND-2');

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->call('approve', $d1->id)
        ->assertDispatched('notify');

    $approved = Device::find($d1->id);
    expect($approved->status)->toBe(DeviceStatus::Active)
        ->and($approved->api_key_hash)->not->toBeNull()
        ->and($approved->pending_api_key)->not->toBeNull()
        ->and($approved->api_key_issued_at)->not->toBeNull()
        ->and($approved->api_key_claimed_at)->toBeNull();

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->call('reject', $d2->id)
        ->assertDispatched('notify');

    expect(Device::find($d2->id)->status)->toBe(DeviceStatus::Revoked);
});

it('stores only a hash and an encrypted copy of the issued key', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['hostname' => 'HASH-PC']);

    Livewire::actingAs($user)->test(Pending::class)->call('approve', $device->id);

    $apiKey = $device->fresh()->pending_api_key;
    $row = (array) DB::table('devices')->where('id', $device->id)->first();

    expect(Schema::hasColumn('devices', 'api_key'))->toBeFalse()
        ->and($apiKey)->toBeString()->toHaveLength(64)
        ->and($row['api_key_hash'])->toBe(Device::hashApiKey($apiKey))
        ->and($row['pending_api_key'])->not->toBe($apiKey)
        ->and(collect($row)->contains($apiKey))->toBeFalse();
});

it('does not approve a revoked device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->create(['status' => DeviceStatus::Revoked]);

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->call('approve', $device->id)
        ->assertDispatched('notify', message: 'Only pending devices can be approved');

    $device->refresh();
    expect($device->status)->toBe(DeviceStatus::Revoked)
        ->and($device->api_key_hash)->toBeNull()
        ->and($device->pending_api_key)->toBeNull();
});

it('does not re-issue a key for an already active device', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('KEY-KEEP')->create();

    Livewire::actingAs($user)->test(Pending::class)->call('approve', $device->id);

    $device->refresh();
    expect($device->api_key_hash)->toBe(Device::hashApiKey('KEY-KEEP'))
        ->and($device->pending_api_key)->toBeNull();
});

it('warns when a pending device reuses an enrolled hostname', function (): void {
    $user = User::factory()->create();
    Device::factory()->withApiKey('KEY-REAL')->create(['hostname' => 'FINANCE-PC']);
    Device::factory()->create(['hostname' => 'finance-pc', 'status' => DeviceStatus::Pending]);
    Device::factory()->create(['hostname' => 'BRAND-NEW-PC', 'status' => DeviceStatus::Pending]);

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->assertSee('Duplicate hostnames detected')
        ->assertSee('Hostname already enrolled')
        ->assertViewHas('enrolledHostnames', ['finance-pc' => true]);
});

it('does not warn when hostnames are unique', function (): void {
    $user = User::factory()->create();
    Device::factory()->withApiKey('KEY-REAL')->create(['hostname' => 'FINANCE-PC']);
    Device::factory()->create(['hostname' => 'HR-PC', 'status' => DeviceStatus::Pending]);

    Livewire::actingAs($user)
        ->test(Pending::class)
        ->assertDontSee('Hostname already enrolled')
        ->assertDontSee('Duplicate hostnames detected');
});
