<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Livewire\Devices\Printers;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DevicePrinter;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function tabPc(array $attributes = []): Device
{
    return Device::factory()->windows()->withApiKey()->create([
        'hostname' => 'LABEL-PC-1',
        'agent_version' => '0.9.0',
        'last_seen' => now(),
        'printers_reported_at' => now(),
        ...$attributes,
    ]);
}

function epsonSnapshot(): array
{
    return [
        'name' => 'Epson ET9C934EE3ADF7',
        'port_name' => 'WSD-1234',
        'driver_name' => 'EPSON ET-2850 Series',
        'status' => 0x82,
        'attributes' => 0,
        'jobs_count' => 1,
        'jobs' => [[
            'id' => 41,
            'document' => 'Invoice 1001.pdf',
            'user_name' => 'sophie',
            'status' => 0x2012,
            'status_text' => null,
            'submitted' => now()->subMinutes(12)->toIso8601ZuluString(),
            'total_pages' => 3,
            'pages_printed' => 1,
            'size' => 52000,
            'position' => 1,
            'priority' => 1,
        ]],
    ];
}

beforeEach(function (): void {
    $this->freezeSecond();
    app(SyncSystemScripts::class)();
    $this->actingAs($this->user = User::factory()->create());
});

it('says when the agent has not reported printers yet', function (): void {
    Livewire::test(Printers::class, ['device' => tabPc(['printers_reported_at' => null])])
        ->assertSeeHtml('data-printers-empty')
        ->assertSee("Agent hasn't reported printers yet", false);
});

it('explains that only Windows PCs are watched', function (): void {
    Livewire::test(Printers::class, ['device' => Device::factory()->linux()->withApiKey()->create()])
        ->assertSee('Printers are only watched on Windows PCs.');
});

it('shows each printer with its status, problem and queue', function (): void {
    $device = tabPc();
    DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot(), 'problem_since' => now()]);

    Livewire::test(Printers::class, ['device' => $device])
        ->assertSeeHtml('data-printer="Epson ET9C934EE3ADF7"')
        ->assertSee(['WSD-1234', 'EPSON ET-2850 Series', '1 job'])
        ->assertSeeInOrder(['Error', 'Offline'])
        ->assertSee('Error, Offline, 1 job in error, oldest waiting 12m')
        ->assertSee(['Invoice 1001.pdf', 'sophie', '#41', '12m', '1 of 3'])
        ->assertSeeInOrder(['Error', 'Printing', 'Retained'])
        ->assertSeeHtml('data-cancel-job="41"')
        ->assertSeeHtml('data-clear-queue');
});

it('updates the queue live when a new snapshot is broadcast', function (): void {
    $device = tabPc();
    $printer = DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot()]);

    $component = Livewire::test(Printers::class, ['device' => $device])->assertSee('Invoice 1001.pdf');

    $printer->forceFill(['snapshot' => [...epsonSnapshot(), 'status' => 0, 'jobs_count' => 0, 'jobs' => []]])->save();

    $component->dispatch("echo-private:devices.{$device->id},PrintersReported", ['deviceId' => $device->id])
        ->assertDontSee('Invoice 1001.pdf')
        ->assertSee('No jobs queued.')
        ->assertDontSeeHtml('data-printer-problem');
});

it('lists only the first jobs of a flooded queue and counts the rest', function (): void {
    config(['printers.jobs_shown' => 2]);
    $device = tabPc();
    $jobs = collect(range(1, 5))->map(fn (int $id): array => ['id' => $id, 'document' => "Label {$id}", 'status' => 0, 'submitted' => now()->toIso8601ZuluString()])->all();
    DevicePrinter::factory()->for($device)->create(['name' => 'Zebra', 'snapshot' => ['name' => 'Zebra', 'status' => 0, 'jobs_count' => 104, 'jobs' => $jobs]]);

    Livewire::test(Printers::class, ['device' => $device])
        ->assertSee(['Label 1', 'Label 2'])
        ->assertDontSee('Label 3')
        ->assertSee('And 102 more jobs.');
});

it('warns when the print spooler is down', function (): void {
    Livewire::test(Printers::class, ['device' => tabPc(['spooler_down_since' => now()])])
        ->assertSeeHtml('data-spooler-down')
        ->assertSee('The print spooler is not running');
});

it('queues the print queue scripts with the printer name and job as parameters', function (string $method, array $arguments, string $slug, array $parameters): void {
    $device = tabPc();
    $printer = DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot()]);

    Livewire::test(Printers::class, ['device' => $device])
        ->call($method, ...array_map(fn (mixed $argument): mixed => $argument === 'printer' ? $printer->id : $argument, $arguments))
        ->assertHasNoErrors()
        ->assertDispatched('command-queued');

    $command = DeviceCommand::query()->with('script')->sole();
    expect($command->script->slug)->toBe($slug)
        ->and($command->parameters)->toBe($parameters === [] ? null : $parameters)
        ->and($command->status)->toBe(CommandStatus::Pending)
        ->and($command->queued_by)->toBe($this->user->id);
})->with([
    'clear queue' => ['clearQueue', ['printer'], 'clear-print-queue', ['PrinterName' => 'Epson ET9C934EE3ADF7']],
    'test page' => ['printTestPage', ['printer'], 'print-test-page', ['PrinterName' => 'Epson ET9C934EE3ADF7']],
    'cancel job' => ['cancelJob', ['printer', 41], 'cancel-print-job', ['PrinterName' => 'Epson ET9C934EE3ADF7', 'JobId' => '41']],
    'restart spooler' => ['restartSpooler', [], 'restart-print-spooler', []],
]);

it('refuses a job that is not in the printer\'s latest snapshot, or another device\'s printer', function (): void {
    $device = tabPc();
    $printer = DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot()]);
    $elsewhere = DevicePrinter::factory()->create(['name' => 'Other']);

    Livewire::test(Printers::class, ['device' => $device])->call('cancelJob', $printer->id, 999)->assertNotFound();
    expect(fn () => Livewire::test(Printers::class, ['device' => $device])->call('clearQueue', $elsewhere->id))->toThrow(ModelNotFoundException::class);

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('shows the running command in place of the button', function (): void {
    $device = tabPc();
    $printer = DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot()]);

    $component = Livewire::test(Printers::class, ['device' => $device])->call('clearQueue', $printer->id);

    $component->assertDontSeeHtml('data-clear-queue')->assertSeeHtml('data-run-button-busy')->assertSee('Queued...');
});

it('hides management buttons from users who cannot run commands', function (): void {
    $device = tabPc(['is_monitor_only' => true]);
    DevicePrinter::factory()->for($device)->create(['name' => 'Epson ET9C934EE3ADF7', 'snapshot' => epsonSnapshot()]);

    Livewire::test(Printers::class, ['device' => $device])
        ->assertDontSeeHtml('data-clear-queue')
        ->assertDontSeeHtml('data-cancel-job')
        ->assertDontSeeHtml('data-restart-spooler');
});

it('is served at its own device tab route', function (): void {
    $device = tabPc();

    $this->get(route('devices.printers', $device))
        ->assertSuccessful()
        ->assertSee('LABEL-PC-1 · Printers', false);
});
