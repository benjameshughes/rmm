<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\User;
use Livewire\Livewire;

function printStation(string $hostname, array $attributes = []): Device
{
    return Device::factory()->withApiKey()->create([
        'hostname' => $hostname,
        'last_seen' => now(),
        'virtual_printer_seen_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->freezeSecond();
    $this->actingAs(User::factory()->create());
});

it('shows a pill per online print station, down ones first', function (): void {
    printStation('LABEL-A');
    printStation('LABEL-B', ['virtual_printer_seen_at' => now()->subDay(), 'virtual_printer_missing_since' => now()->subHours(14)]);

    Livewire::test(Dashboard::class)
        ->assertSeeHtml('data-label-printing')
        ->assertSeeInOrder(['Label printing', 'LABEL-B', 'Down for 14h', 'LABEL-A', 'Ready'])
        ->assertSeeHtml('data-print-station="down"')
        ->assertSeeHtml('data-print-station="ready"');
});

it('leaves out pcs that are not print stations or not plainly online', function (): void {
    printStation('NEVER-PRINTS', ['virtual_printer_seen_at' => null]);
    printStation('LABEL-OFF', ['last_seen' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()]);
    printStation('LABEL-SLEEPING', ['power_state' => 'powering_off', 'power_state_changed_at' => now()]);
    printStation('LABEL-BOOTING', ['power_state' => 'powering_on', 'power_state_changed_at' => now()]);

    Livewire::test(Dashboard::class)
        ->assertDontSeeHtml('data-label-printing')
        ->assertDontSeeHtml('data-print-station');
});

it('flips a pill live when the device update arrives over reverb', function (): void {
    $device = printStation('LABEL-A');
    $component = Livewire::test(Dashboard::class)->assertSeeHtml('data-print-station="ready"');

    $this->travel(config('dashboard.heartbeat_refresh_seconds'))->seconds();
    $device->forceFill(['last_seen' => now(), 'virtual_printer_missing_since' => now()->subMinutes(3)])->save();

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $device->id, 'isStateChange' => true])
        ->assertSeeHtml('data-print-station="down"')
        ->assertSee('Down for 3m');
});

it('flags a down print station on its devices table row', function (): void {
    printStation('LABEL-DOWN', ['virtual_printer_missing_since' => now()->subMinutes(20)]);

    Livewire::test(Index::class)
        ->assertSeeHtml('data-virtual-printer="down"')
        ->assertSee('Virtual Printer');
});

it('shows a ready print station as ready on its devices table row', function (): void {
    printStation('LABEL-PC');

    Livewire::test(Index::class)
        ->assertSeeHtml('data-virtual-printer="ready"')
        ->assertSee('Ready');
});

it('shows no badge for an offline print station or a PC that is not a station', function (Closure $attributes): void {
    Device::factory()->windows()->active()->create($attributes());

    Livewire::test(Index::class)->assertDontSeeHtml('data-virtual-printer=');
})->with([
    // Closures so the timestamps are taken when each case runs, not when the suite loads.
    'offline station' => [fn (): array => ['virtual_printer_seen_at' => now()->subHour(), 'last_seen' => now()->subHour(), 'virtual_printer_missing_since' => now()->subHour()]],
    'not a station' => [fn (): array => []],
]);

it('shows the status on the device page header and updates it live', function (): void {
    $device = printStation('LABEL-HEAD');

    $component = Livewire::test(Header::class, ['device' => $device])
        ->assertSeeHtml('data-virtual-printer="ready"');

    $device->forceFill(['virtual_printer_missing_since' => now()->subMinutes(10)])->save();

    $component->dispatch("echo-private:devices.{$device->id},DeviceUpdated")
        ->assertSeeHtml('data-virtual-printer="down"')
        ->assertSee('Down for 10m');
});
