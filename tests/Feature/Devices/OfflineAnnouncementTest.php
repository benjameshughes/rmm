<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Events\DeviceUpdated;
use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('announces a device the moment it crosses the offline threshold', function (): void {
    Event::fake([DeviceUpdated::class]);
    $device = Device::factory()->active()->create(['last_seen' => now()->subMinutes(5)->subSeconds(30)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id);
});

it('announces it even when no offline alert rule exists', function (): void {
    Event::fake([DeviceUpdated::class]);
    Device::factory()->active()->create(['last_seen' => now()->subMinutes(6)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertDispatchedTimes(DeviceUpdated::class, 1);
});

it('leaves online, long-offline and pending devices alone', function (array $attributes): void {
    Event::fake([DeviceUpdated::class]);
    Device::factory()->create($attributes);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertNotDispatched(DeviceUpdated::class);
})->with([
    'online' => [['status' => DeviceStatus::Active, 'last_seen' => now()->subMinutes(2)]],
    'offline for hours' => [['status' => DeviceStatus::Active, 'last_seen' => now()->subHours(3)]],
    'never seen' => [['status' => DeviceStatus::Active, 'last_seen' => null]],
    'pending approval' => [['status' => DeviceStatus::Pending, 'last_seen' => now()->subMinutes(6)]],
]);

it('follows the configured threshold', function (): void {
    Event::fake([DeviceUpdated::class]);
    config(['devices.online.threshold_minutes' => 10]);
    $device = Device::factory()->active()->create(['last_seen' => now()->subMinutes(6)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertNotDispatched(DeviceUpdated::class);
    expect($device->isOnline)->toBeTrue();
});

it('flips the device list to Offline when the announcement arrives', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subMinutes(4)]);

    $component = Livewire::actingAs(User::factory()->create())->test(Index::class)
        ->assertSee('Online');

    $this->travel(2)->minutes();

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
        ->assertSee('Offline');
});
