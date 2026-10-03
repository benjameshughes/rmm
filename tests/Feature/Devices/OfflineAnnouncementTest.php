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

beforeEach(function (): void {
    config(['devices.heartbeat.interval_seconds' => 15, 'devices.online.missed_heartbeats' => 3]);
});

it('announces a device the moment it crosses the offline threshold', function (): void {
    Event::fake([DeviceUpdated::class]);
    $device = Device::factory()->active()->create(['last_seen' => now()->subSeconds(60)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertDispatched(DeviceUpdated::class, fn (DeviceUpdated $event): bool => $event->deviceId === $device->id);
});

it('announces it even when no offline alert rule exists', function (): void {
    Event::fake([DeviceUpdated::class]);
    Device::factory()->active()->create(['last_seen' => now()->subSeconds(90)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertDispatchedTimes(DeviceUpdated::class, 1);
});

it('leaves online, long-offline and pending devices alone', function (Closure $attributes): void {
    Event::fake([DeviceUpdated::class]);
    Device::factory()->create($attributes());

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertNotDispatched(DeviceUpdated::class);
})->with([
    // Closures so the timestamps are taken when each case runs, not when the suite loads.
    'online' => [fn (): array => ['status' => DeviceStatus::Active, 'last_seen' => now()->subSeconds(20)]],
    'offline for hours' => [fn (): array => ['status' => DeviceStatus::Active, 'last_seen' => now()->subHours(3)]],
    'never seen' => [fn (): array => ['status' => DeviceStatus::Active, 'last_seen' => null]],
    'pending approval' => [fn (): array => ['status' => DeviceStatus::Pending, 'last_seen' => now()->subSeconds(90)]],
]);

it('follows the configured threshold', function (): void {
    Event::fake([DeviceUpdated::class]);
    config(['devices.online.missed_heartbeats' => 10]);
    $device = Device::factory()->active()->create(['last_seen' => now()->subSeconds(90)]);

    $this->artisan('devices:check-offline')->assertSuccessful();

    Event::assertNotDispatched(DeviceUpdated::class);
    expect($device->isOnline)->toBeTrue();
});

it('flips the device list to Offline when the announcement arrives', function (): void {
    $device = Device::factory()->active()->create(['last_seen' => now()->subSeconds(30)]);

    $component = Livewire::actingAs(User::factory()->create())->test(Index::class)
        ->assertSee('Online');

    $this->travel(30)->seconds();

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'status' => 'active'])
        ->assertSee('Offline');
});
