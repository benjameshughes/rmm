<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Enums\PrinterAction;
use App\Exceptions\ZebraTemplateRefused;
use App\Livewire\Devices\Printers;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DevicePrinter;
use App\Models\Script;
use App\Models\User;
use App\Models\ZebraTemplate;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->freezeSecond();
    app(SyncSystemScripts::class)();
    $this->actingAs($this->user = User::factory()->create());
    $this->device = Device::factory()->windows()->withApiKey()->create([
        'hostname' => 'LABEL-PC-1',
        'agent_version' => '0.9.0',
        'last_seen' => now(),
        'printers_reported_at' => now(),
    ]);
});

function zebraPrinter(Device $device, string $name = 'ZDesigner GK420d', string $driver = 'ZDesigner GK420d'): DevicePrinter
{
    return DevicePrinter::factory()->for($device)->create([
        'name' => $name,
        'snapshot' => ['name' => $name, 'port_name' => 'USB001', 'driver_name' => $driver, 'status' => 0, 'attributes' => 0, 'jobs_count' => 0, 'jobs' => []],
    ]);
}

function runningCapture(Device $device, User $user, string $slug = 'capture-zebra-template'): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem($slug)->id,
        'status' => CommandStatus::Running,
        'parameters' => ['PrinterName' => 'ZDesigner GK420d'],
        'queued_by' => $user->id,
        'sent_at' => now(),
        'started_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function captureOutput(array $overrides = []): string
{
    return "OK: saved the settings of 'ZDesigner GK420d' as the Zebra template\n".json_encode([
        'status' => 'ok',
        'exit_code' => 0,
        'printer_name' => 'ZDesigner GK420d',
        'driver_name' => 'ZDesigner GK420d',
        'port_name' => 'USB001',
        'settings_b64' => base64_encode(gzencode('devmode bytes')),
        'captured_at' => now()->toIso8601ZuluString(),
        ...$overrides,
    ]);
}

it('stores a successful capture as the Zebra template', function (): void {
    runningCapture($this->device, $this->user)->markAsCompleted(captureOutput(), 0);

    expect(ZebraTemplate::current())
        ->device_id->toBe($this->device->id)
        ->source_hostname->toBe('LABEL-PC-1')
        ->printer_name->toBe('ZDesigner GK420d')
        ->driver_name->toBe('ZDesigner GK420d')
        ->settings->toBe(base64_encode(gzencode('devmode bytes')))
        ->captured_at->not->toBeNull();
});

it('replaces the previous template so only one is ever active', function (): void {
    ZebraTemplate::factory()->create(['source_hostname' => 'OLD-PC']);

    runningCapture($this->device, $this->user)->markAsCompleted(captureOutput(), 0);

    expect(ZebraTemplate::query()->count())->toBe(1)
        ->and(ZebraTemplate::current()->source_hostname)->toBe('LABEL-PC-1');
});

it('refuses captures that are not usable as a template', function (string $output, int $exitCode): void {
    ZebraTemplate::factory()->create(['source_hostname' => 'OLD-PC']);

    runningCapture($this->device, $this->user)->markAsCompleted($output, $exitCode);

    expect(ZebraTemplate::query()->sole()->source_hostname)->toBe('OLD-PC');
})->with([
    'seagull driver' => fn (): array => [captureOutput(['driver_name' => 'Zebra GK420d - ZPL']), 0],
    'not base64' => fn (): array => [captureOutput(['settings_b64' => 'not base64!']), 0],
    'empty settings' => fn (): array => [captureOutput(['settings_b64' => '']), 0],
    'too big for an environment variable' => fn (): array => [captureOutput(['settings_b64' => str_repeat('A', 32004)]), 0],
    'no json' => fn (): array => ["ATTENTION: There is no printer named 'x' on this PC", 0],
    'failed run' => fn (): array => [captureOutput(), 1],
]);

it('ignores the same output from any other script', function (): void {
    runningCapture($this->device, $this->user, 'print-test-page')->markAsCompleted(captureOutput(), 0);

    expect(ZebraTemplate::query()->count())->toBe(0);
});

it('keeps the template when its source PC is deleted', function (): void {
    runningCapture($this->device, $this->user)->markAsCompleted(captureOutput(), 0);

    $this->device->delete();

    expect(ZebraTemplate::current())->device_id->toBeNull()->source_hostname->toBe('LABEL-PC-1');
});

it('queues install-zebra from the Printers tab with no template or driver values in its parameters', function (): void {
    ZebraTemplate::factory()->create();

    Livewire::test(Printers::class, ['device' => $this->device])
        ->call('installZebra')
        ->assertHasNoErrors()
        ->assertDispatched('command-queued');

    $command = DeviceCommand::query()->with('script')->sole();

    expect($command->script->slug)->toBe(PrinterAction::InstallZebra->value)
        ->and($command->parameters)->toBeNull()
        ->and($command->queued_by)->toBe($this->user->id);
});

it('queues capture-zebra-template for a ZDesigner printer', function (): void {
    $printer = zebraPrinter($this->device);

    Livewire::test(Printers::class, ['device' => $this->device])
        ->call('captureZebraTemplate', $printer->id)
        ->assertHasNoErrors()
        ->assertDispatched('command-queued');

    $command = DeviceCommand::query()->with('script')->sole();

    expect($command->script->slug)->toBe(PrinterAction::CaptureZebraTemplate->value)
        ->and($command->parameters)->toBe(['PrinterName' => 'ZDesigner GK420d']);
});

it('refuses to capture a printer that is not on the ZDesigner driver', function (): void {
    $printer = zebraPrinter($this->device, 'Zebra GK420d - ZPL', 'Zebra GK420d - ZPL');

    expect(fn () => Livewire::test(Printers::class, ['device' => $this->device])->call('captureZebraTemplate', $printer->id))
        ->toThrow(ZebraTemplateRefused::class, 'Only a printer on the ZDesigner driver can be the Zebra template');

    expect(DeviceCommand::query()->count())->toBe(0)
        ->and((new ZebraTemplateRefused)->getStatusCode())->toBe(422);
});

it('shows Install Zebra, and Use as Zebra template only on ZDesigner printers', function (): void {
    zebraPrinter($this->device);
    zebraPrinter($this->device, 'Zebra GK420d - ZPL', 'Zebra GK420d - ZPL');

    Livewire::test(Printers::class, ['device' => $this->device])
        ->assertSeeHtml('data-install-zebra')
        ->assertSee('No Zebra template yet.')
        ->assertSeeHtml('data-capture-zebra-template')
        ->assertSeeInOrder(['data-printer="ZDesigner GK420d"', 'data-capture-zebra-template', 'data-printer="Zebra GK420d - ZPL"'], false);

    expect(substr_count(Livewire::test(Printers::class, ['device' => $this->device])->html(), 'data-capture-zebra-template="'))->toBe(1);
});

it('shows the current template on the Printers tab', function (): void {
    ZebraTemplate::factory()->create(['source_hostname' => 'PACKING-PC', 'captured_at' => now()->subDays(2)]);

    Livewire::test(Printers::class, ['device' => $this->device])
        ->assertSee('Zebra template: ZDesigner GK420d on PACKING-PC, captured 2 days ago.');
});

it('shows the running install in place of its button', function (): void {
    Livewire::test(Printers::class, ['device' => $this->device])
        ->call('installZebra')
        ->assertDontSeeHtml('data-install-zebra')
        ->assertSeeHtml('data-run-button-busy');
});

it('hides the Zebra buttons and refuses them on a PC that cannot run commands', function (): void {
    $this->device->forceFill(['is_monitor_only' => true])->save();
    $printer = zebraPrinter($this->device);

    Livewire::test(Printers::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-install-zebra')
        ->assertDontSeeHtml('data-capture-zebra-template')
        ->call('installZebra')
        ->assertForbidden();

    Livewire::test(Printers::class, ['device' => $this->device])->call('captureZebraTemplate', $printer->id)->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});
