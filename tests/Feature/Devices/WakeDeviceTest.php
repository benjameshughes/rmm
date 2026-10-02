<?php

declare(strict_types=1);

use App\Actions\Device\WakeDevice;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

/**
 * Point Wake-on-LAN at a UDP socket on localhost so the test reads the real packets.
 *
 * @return resource
 */
function listenForWakePackets()
{
    $listener = stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
    stream_set_blocking($listener, false);

    config([
        'devices.wake_on_lan.broadcast_address' => '127.0.0.1',
        'devices.wake_on_lan.port' => (int) str(stream_socket_get_name($listener, false))->afterLast(':')->value(),
    ]);

    return $listener;
}

/**
 * @param  resource  $listener
 * @return array<int, string>
 */
function receivedWakePackets($listener): array
{
    return collect(range(1, 10))
        ->map(fn (): string|false => stream_socket_recvfrom($listener, 1024))
        ->filter()
        ->values()
        ->all();
}

function magicPacketFor(string $mac): string
{
    return str_repeat("\xFF", 6).str_repeat(hex2bin(str_replace(':', '', $mac)), 16);
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('sends a magic packet to every MAC the device reported', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['mac_addresses' => ['BC:24:11:8D:62:14', '00:4E:01:B6:32:71']]);

    app(WakeDevice::class)($device);

    expect(receivedWakePackets($listener))->toBe([
        magicPacketFor('BC:24:11:8D:62:14'),
        magicPacketFor('00:4E:01:B6:32:71'),
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

    $page = Livewire::actingAs($this->user)->test(Show::class, ['device' => $device]);

    $isWakeable ? $page->assertSeeHtml('wire:click="wake"') : $page->assertDontSeeHtml('wire:click="wake"');
})->with([
    'asleep with a MAC' => [['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], true],
    'online' => [['last_seen' => now(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']], false],
    'asleep without a MAC' => [['last_seen' => now()->subHour(), 'mac_addresses' => null], false],
    'asleep with an empty list' => [['last_seen' => now()->subHour(), 'mac_addresses' => []], false],
]);

it('wakes the device from its page and toasts', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->call('wake')
        ->assertDispatched('toast-show');

    expect(receivedWakePackets($listener))->toBe([magicPacketFor('AA:BB:CC:DD:EE:FF')]);
});

it('checks the wake ability before sending anything', function (): void {
    $listener = listenForWakePackets();
    $device = Device::factory()->active()->create(['last_seen' => now()->subHour(), 'mac_addresses' => ['AA:BB:CC:DD:EE:FF']]);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'wake' ? false : null);

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->call('wake')
        ->assertForbidden();

    expect(receivedWakePackets($listener))->toBeEmpty();
});
