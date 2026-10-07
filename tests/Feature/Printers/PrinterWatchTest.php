<?php

declare(strict_types=1);

use App\Actions\Device\AnnounceDevicesGoneOffline;
use App\Actions\Device\RecordDevicePowerEvent;
use App\Actions\Printer\StorePrinterSnapshot;
use App\DTOs\Printers\PrinterSnapshot;
use App\Enums\DevicePowerState;
use App\Enums\PowerEventReason;
use App\Livewire\Dashboard;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Models\Device;
use App\Models\DevicePrinter;
use App\Models\User;
use Livewire\Livewire;

function watchedPc(string $hostname, array $attributes = []): Device
{
    return Device::factory()->windows()->withApiKey()->create([
        'hostname' => $hostname,
        'last_seen' => now(),
        'printers_reported_at' => now(),
        ...$attributes,
    ]);
}

/**
 * Store a snapshot of one printer for the device, as the API would.
 */
function reportPrinter(Device $device, string $name, int $status = 0, int $jobsCount = 0, bool $isSpoolerAvailable = true): void
{
    app(StorePrinterSnapshot::class)($device->fresh(), PrinterSnapshot::fromArray([
        'trigger' => 'tick',
        'collected_at' => now()->toIso8601ZuluString(),
        'spooler_available' => $isSpoolerAvailable,
        'printers' => [['name' => $name, 'status' => $status, 'jobs_count' => $jobsCount, 'jobs' => []]],
    ], config('printers.ignored_names')));
}

beforeEach(function (): void {
    $this->freezeSecond();
    $this->actingAs(User::factory()->create());
});

it('starts the problem clock once and keeps it while the problem lasts', function (): void {
    $device = watchedPc('LABEL-PC');

    reportPrinter($device, 'Zebra', status: 0x80);
    $this->travel(3)->minutes();
    $device->forceFill(['last_seen' => now()])->save();
    reportPrinter($device, 'Zebra', status: 0x80);

    expect(DevicePrinter::query()->sole()->problem_since->equalTo(now()->subMinutes(3)))->toBeTrue();

    reportPrinter($device, 'Zebra');
    expect(DevicePrinter::query()->sole()->problem_since)->toBeNull();
});

it('does not judge printers on a PC that is not plainly online', function (Closure $attributes): void {
    $device = watchedPc('LABEL-PC', $attributes());

    reportPrinter($device, 'Zebra', status: 0x80);
    reportPrinter($device, 'Zebra', isSpoolerAvailable: false);

    expect(DevicePrinter::query()->sole()->problem_since)->toBeNull()
        ->and($device->fresh()->spooler_down_since)->toBeNull();
})->with([
    // Closures so the timestamps are taken when each case runs, not when the suite loads.
    'offline' => [fn (): array => ['last_seen' => now()->subHour()]],
    'powering on' => [fn (): array => ['power_state' => DevicePowerState::PoweringOn, 'power_state_changed_at' => now()]],
    'powering off' => [fn (): array => ['power_state' => DevicePowerState::PoweringOff, 'power_state_changed_at' => now()]],
]);

it('stops judging printers once their reports go stale', function (): void {
    $device = watchedPc('LABEL-PC');
    DevicePrinter::factory()->offline()->for($device)->create(['name' => 'Zebra']);

    expect($device->fresh()->printerProblemLabel())->toBe('Zebra: Offline');

    $device->forceFill(['printers_reported_at' => now()->subMinutes(config('printers.stale_after_minutes') + 1)])->save();
    expect($device->fresh()->printerProblemLabel())->toBeNull();
});

it('clears problem clocks when the PC goes offline or powers off', function (Closure $pause): void {
    $device = watchedPc('LABEL-PC', ['spooler_down_since' => now()]);
    DevicePrinter::factory()->offline()->for($device)->create(['name' => 'Zebra']);

    $pause($device);

    expect(DevicePrinter::query()->sole()->problem_since)->toBeNull()
        ->and($device->fresh()->spooler_down_since)->toBeNull();
})->with([
    'gone offline' => [function (Device $device): void {
        $device->forceFill(['last_seen' => Device::onlineCutoff()->subSecond()])->save();
        app(AnnounceDevicesGoneOffline::class)();
    }],
    'powering off' => [fn (Device $device) => app(RecordDevicePowerEvent::class)($device, DevicePowerState::PoweringOff, PowerEventReason::Sleep)],
]);

it('names the worst printer problem on the device header and updates it live', function (): void {
    $device = watchedPc('LABEL-PC');
    DevicePrinter::factory()->for($device)->create(['name' => 'Zebra GK420d - ZPL']);

    $component = Livewire::test(Header::class, ['device' => $device])->assertDontSeeHtml('data-printer-problem-badge');

    DevicePrinter::query()->sole()->forceFill(['snapshot' => ['name' => 'Zebra GK420d - ZPL', 'status' => 0, 'jobs_count' => 104, 'jobs' => []], 'problem_since' => now()])->save();

    $component->dispatch("echo-private:devices.{$device->id},PrintersReported")
        ->assertSeeHtml('data-printer-problem-badge')
        ->assertSee('Zebra GK420d - ZPL: 104 jobs queued');
});

it('puts the worst problem first and counts the rest on the devices table', function (): void {
    $device = watchedPc('LABEL-PC');
    DevicePrinter::factory()->for($device)->create(['name' => 'Warehouse', 'snapshot' => ['name' => 'Warehouse', 'status' => 0, 'jobs_count' => 30, 'jobs' => []], 'problem_since' => now()]);
    DevicePrinter::factory()->offline()->for($device)->create(['name' => 'Epson']);

    Livewire::test(Index::class)
        ->assertSeeHtml('data-printer-problem-badge')
        ->assertSee('Epson: Offline (+1 more)');
});

it('shows the stopped spooler ahead of any printer', function (): void {
    $device = watchedPc('LABEL-PC', ['spooler_down_since' => now()]);
    DevicePrinter::factory()->offline()->for($device)->create(['name' => 'Epson']);

    expect($device->fresh()->printerProblemLabel())->toBe('Print spooler not running');
});

it('shows no printer badge on an offline PC', function (): void {
    $device = watchedPc('LABEL-PC', ['last_seen' => now()->subHour()]);
    DevicePrinter::factory()->offline()->for($device)->create(['name' => 'Epson']);

    Livewire::test(Index::class)->assertDontSeeHtml('data-printer-problem-badge');
});

it('lists printer problems across the fleet on the dashboard, worst first', function (): void {
    $flooded = watchedPc('PACKING-PC');
    DevicePrinter::factory()->for($flooded)->create(['name' => 'Warehouse', 'snapshot' => ['name' => 'Warehouse', 'status' => 0, 'jobs_count' => 104, 'jobs' => []], 'problem_since' => now()->subMinutes(30)]);
    $broken = watchedPc('OFFICE-PC');
    DevicePrinter::factory()->offline(forMinutes: 2)->for($broken)->create(['name' => 'Epson ET9C934EE3ADF7']);
    watchedPc('SPOOLER-PC', ['spooler_down_since' => now()->subMinute()]);
    $offline = watchedPc('GONE-PC', ['last_seen' => now()->subHour()]);
    DevicePrinter::factory()->offline()->for($offline)->create(['name' => 'Zebra']);
    DevicePrinter::factory()->for(watchedPc('FINE-PC'))->create(['name' => 'Xerox3335']);

    Livewire::test(Dashboard::class)
        ->assertSeeHtml('data-printer-problems')
        ->assertSeeInOrder(['Printers', 'SPOOLER-PC', 'Print spooler not running', 'OFFICE-PC', 'Epson ET9C934EE3ADF7', 'Offline', 'PACKING-PC', 'Warehouse', '104 jobs queued'])
        ->assertDontSeeHtml(route('devices.printers', $offline))
        ->assertDontSee('Xerox3335');
});

it('hides the dashboard printers card when nothing is wrong', function (): void {
    DevicePrinter::factory()->for(watchedPc('FINE-PC'))->create(['name' => 'Xerox3335']);

    Livewire::test(Dashboard::class)->assertDontSeeHtml('data-printer-problems');
});
