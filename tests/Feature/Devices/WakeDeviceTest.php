<?php

declare(strict_types=1);

use App\Actions\Device\WakeDevice;
use App\Enums\DevicePowerState;
use App\Livewire\Devices\Header;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('sends a magic packet to every MAC the device reported', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['mac_addresses' => ['BC:24:11:8D:62:14', '00:4E:01:B6:32:71']]);

    app(WakeDevice::class)($device);

    expect(receivedWakePackets($listener))->toBe([
        ...array_fill(0, 3, magicPacketFor('BC:24:11:8D:62:14')),
        ...array_fill(0, 3, magicPacketFor('00:4E:01:B6:32:71')),
    ]);
});

it('builds the packet as six 0xFF bytes and the MAC sixteen times', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    app(WakeDevice::class)($device);

    $packet = receivedWakePackets($listener)[0];

    expect(strlen($packet))->toBe(102)
        ->and(bin2hex(substr($packet, 0, 6)))->toBe('ffffffffffff')
        ->and(bin2hex(substr($packet, 6)))->toBe(str_repeat('aabbccddeeff', 16));
});

it('refuses to wake a device that never reported a MAC', function (): void {
    $device = Device::factory()->active()->create(['mac_addresses' => null]);

    app(WakeDevice::class)($device);
})->throws(RuntimeException::class, 'has not reported a MAC address yet');

it('shows the wake button only for offline devices with a MAC', function (array $attributes, bool $isWakeable): void {
    $device = Device::factory()->active()->create($attributes);

    expect($device->isWakeable)->toBe($isWakeable);

    $page = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device]);

    $isWakeable ? $page->assertSeeHtml('wire:click="wake"') : $page->assertDontSeeHtml('wire:click="wake"');
})->with([
    'asleep with a MAC' => [['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], true],
    'online' => [['last_seen' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], false],
    'announced sleep inside the online window' => [['last_seen' => now(), 'power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], true],
    'asleep without a MAC' => [['last_seen' => now()->subHour(), 'mac_addresses' => null], false],
    'asleep with an empty list' => [['last_seen' => now()->subHour(), 'mac_addresses' => []], false],
]);

it('wakes the device from its page and toasts', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->call('wake')
        ->assertDispatched('toast-show');

    expect(receivedWakePackets($listener))->toBe(array_fill(0, 3, magicPacketFor('AA:BB:CC:DD:EE:FF')));
});

it('checks the wake ability before sending anything', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'wake' ? false : null);

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->call('wake')
        ->assertForbidden();

    expect(receivedWakePackets($listener))->toBeEmpty();
});

it('sends as many packets per MAC as configured', function (): void {
    $listener = listenForWakePackets();
    config(['devices.wake_on_lan.packets_per_mac' => 5]);
    $device = Device::factory()->active()->create(['mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    app(WakeDevice::class)($device);

    expect(receivedWakePackets($listener))->toHaveCount(5);
});
