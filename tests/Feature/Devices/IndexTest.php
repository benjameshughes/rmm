<?php

declare(strict_types=1);

use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('lists devices with status and snapshots', function (): void {
    $user = User::factory()->create();

    $online = Device::factory()->active()->create([
        'hostname' => 'ONLINE-PC',
        'last_seen' => now(),
    ]);
    DeviceMetric::factory()->create([
        'device_id' => $online->id,
        'cpu' => 12.34,
        'ram' => 56.78,
        'recorded_at' => now(),
    ]);

    $offline = Device::factory()->active()->create([
        'hostname' => 'OFFLINE-PC',
        'last_seen' => now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->get('/devices')
        ->assertSuccessful()
        ->assertSee('Devices')
        ->assertSee('ONLINE-PC')
        ->assertSee('OFFLINE-PC')
        ->assertSee('12%')
        ->assertSee('57%');
});

it('reports a device as offline once it has been silent for five minutes', function (): void {
    $online = Device::factory()->active()->create(['last_seen' => now()->subMinutes(4)]);
    $offline = Device::factory()->active()->create(['last_seen' => now()->subMinutes(10)]);
    $neverSeen = Device::factory()->active()->create(['last_seen' => null]);

    expect($online->isOnline)->toBeTrue();
    expect($offline->isOnline)->toBeFalse();
    expect($neverSeen->isOnline)->toBeFalse();
});

it('shows the offline badge for a stale device', function (): void {
    $user = User::factory()->create();
    Device::factory()->active()->create(['last_seen' => now()->subHour()]);

    $this->actingAs($user)
        ->get('/devices')
        ->assertSee('Offline')
        ->assertDontSee('Online');
});

it('keeps the group filter applied while searching', function (): void {
    $user = User::factory()->create();
    $group = DeviceGroup::factory()->create();

    Device::factory()->active()->create(['hostname' => 'SHOP-IN-GROUP', 'device_group_id' => $group->id]);
    Device::factory()->active()->create(['hostname' => 'SHOP-NO-GROUP', 'last_ip' => '10.0.0.1']);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('groupFilter', (string) $group->id)
        ->set('search', 'SHOP')
        ->assertSee('SHOP-IN-GROUP')
        ->assertDontSee('SHOP-NO-GROUP');
});
