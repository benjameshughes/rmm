<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
