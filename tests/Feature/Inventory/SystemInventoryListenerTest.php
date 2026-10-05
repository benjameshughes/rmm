<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Events\SystemInventorySynced;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceInventory;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1']);
});

function runningInventoryCommand(Device $device, string $slug, User $user): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem($slug)->id,
        'status' => CommandStatus::Running,
        'queued_by' => $user->id,
        'sent_at' => now(),
        'started_at' => now(),
    ]);
}

/**
 * The fixture as the script prints it: one compact line.
 *
 * @param  array<string, mixed>  $overrides  Dotted keys to replace
 */
function systemInventoryOutput(array $overrides = []): string
{
    $data = json_decode(file_get_contents(base_path('tests/Fixtures/system-inventory-dell-latitude.json')), true);
    collect($overrides)->each(function (mixed $value, string $key) use (&$data): void {
        data_set($data, $key, $value);
    });

    return json_encode($data);
}

it('stores a snapshot and promotes the fleet columns when system-inventory completes', function (): void {
    Event::fake([SystemInventorySynced::class]);

    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput(), 0);

    expect($this->device->inventories()->sole())
        ->manufacturer->toBe('Dell Inc.')
        ->model->toBe('Latitude 5440')
        ->serial_number->toBe('7HQ2KZ3')
        ->total_ram_gb->toBe('16.0')
        ->windows_edition->toBe('Microsoft Windows 11 Pro')
        ->windows_build->toBe('22631.4317')
        ->is_bitlocker_on->toBeTrue()
        ->is_secure_boot->toBeTrue()
        ->local_admin_count->toBe(2)
        ->and($this->device->inventories()->sole()->data['monitors'][0]['serial'])->toBe('CN0V1RXJ-FCC00-2BJ-3T1L')
        ->and($this->device->fresh()->system_inventoried_at)->not->toBeNull();

    Event::assertDispatched(SystemInventorySynced::class, fn (SystemInventorySynced $event): bool => $event->deviceId === $this->device->id);
});

it('reads the JSON line from stdout and ignores whatever stderr adds', function (): void {
    $output = "WARNING: something noisy\n".systemInventoryOutput().config('commands.stderr_separator').'{"not": "this"}';

    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted($output, 0);

    expect($this->device->inventories()->sole()->serial_number)->toBe('7HQ2KZ3');
});

it('leaves promoted columns null when the script could not read them', function (): void {
    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput([
        'security.bitlocker' => [],
        'security.secure_boot' => null,
        'users.local_admins' => null,
        'windows.ubr' => null,
        'memory.total_gb' => null,
    ]), 0);

    expect($this->device->inventories()->sole())
        ->is_bitlocker_on->toBeNull()
        ->is_secure_boot->toBeNull()
        ->local_admin_count->toBeNull()
        ->total_ram_gb->toBeNull()
        ->windows_build->toBe('22631');
});

it('reports BitLocker off when the system drive is unprotected', function (): void {
    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput([
        'security.bitlocker' => [['drive' => 'C:', 'is_system_drive' => true, 'protection_status' => 'Off', 'conversion_status' => 'Fully decrypted']],
    ]), 0);

    expect($this->device->inventories()->sole()->is_bitlocker_on)->toBeFalse();
});

it('ignores failed runs, malformed output and other scripts', function (string $slug, string $output, int $exitCode, bool $markFailed): void {
    $command = runningInventoryCommand($this->device, $slug, $this->user);

    $markFailed ? $command->markAsFailed('agent error', $output, $exitCode) : $command->markAsCompleted($output, $exitCode);

    expect(DeviceInventory::query()->count())->toBe(0)
        ->and($this->device->fresh()->system_inventoried_at)->toBeNull();
})->with([
    'exit 1' => fn (): array => ['system-inventory', systemInventoryOutput(), 1, false],
    'marked failed' => fn (): array => ['system-inventory', systemInventoryOutput(), 0, true],
    'attention message' => ['system-inventory', 'ATTENTION: could not read Win32_OperatingSystem', 0, false],
    'truncated json' => ['system-inventory', '{"system": {"manufacturer": "Dell', 0, false],
    'json list' => ['system-inventory', '[{"id": "Mozilla.Firefox"}]', 0, false],
    'another script' => fn (): array => ['system-info', systemInventoryOutput(), 0, false],
]);

it('keeps every run as history with the newest as the latest inventory', function (): void {
    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput(), 0);
    $this->travel(1)->day();
    runningInventoryCommand($this->device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput(['system.serial_number' => 'NEWTAG1']), 0);

    expect($this->device->inventories()->count())->toBe(2)
        ->and($this->device->fresh()->latestInventory->serial_number)->toBe('NEWTAG1');
});

it('never stores an inventory for a monitor-only device', function (): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    runningInventoryCommand($device, 'system-inventory', $this->user)->markAsCompleted(systemInventoryOutput(), 0);

    expect(DeviceInventory::query()->count())->toBe(0);
});
