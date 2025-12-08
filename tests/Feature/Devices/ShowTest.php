<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

