<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.7.1']);
});

function runningCommand(Device $device, string $slug, User $user): DeviceCommand
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

function inventoryJson(): string
{
    return json_encode([['id' => 'Mozilla.Firefox', 'name' => 'Mozilla Firefox', 'installed_version' => '131.0', 'latest_version' => null, 'is_update_available' => false, 'source' => 'winget']]);
}

function inventoryCommandsFor(Device $device): int
{
    return $device->commands()->where('script_id', Script::findSystem('winget-inventory')->id)->count();
}

it('syncs the inventory when winget-inventory completes with exit code 0', function (): void {
    runningCommand($this->device, 'winget-inventory', $this->user)->markAsCompleted(inventoryJson(), 0);

    expect($this->device->software()->pluck('package_id')->all())->toBe(['Mozilla.Firefox']);
});

it('ignores inventory runs that failed, and other scripts that print JSON', function (string $slug, int $exitCode, bool $markFailed): void {
    $command = runningCommand($this->device, $slug, $this->user);

    $markFailed ? $command->markAsFailed('agent error', inventoryJson(), $exitCode) : $command->markAsCompleted(inventoryJson(), $exitCode);

    expect(DeviceSoftware::query()->count())->toBe(0);
})->with([
    'inventory exit 1' => ['winget-inventory', 1, false],
    'inventory failed' => ['winget-inventory', 0, true],
    'another script' => ['system-info', 0, false],
]);

it('queues a fresh inventory after an install, upgrade or uninstall finishes', function (string $slug, bool $succeeded): void {
    $command = runningCommand($this->device, $slug, $this->user);

    $succeeded ? $command->markAsCompleted('OK: done', 0) : $command->markAsCompleted('ATTENTION: failed', 1);

    $refresh = $this->device->commands()->where('script_id', Script::findSystem('winget-inventory')->id)->sole();
    expect($refresh)
        ->status->toBe(CommandStatus::Pending)
        ->queued_by->toBe($this->user->id);
})->with([
    'install' => ['winget-install', true],
    'upgrade' => ['winget-upgrade', true],
    'uninstall that failed' => ['winget-uninstall', false],
]);

it('never queues an inventory after an inventory, so refreshes cannot loop', function (): void {
    runningCommand($this->device, 'winget-inventory', $this->user)->markAsCompleted(inventoryJson(), 0);

    expect(inventoryCommandsFor($this->device))->toBe(1);
});

it('does not refresh after a cancelled change or one that is still running', function (): void {
    $pending = DeviceCommand::factory()->pending()->create(['device_id' => $this->device->id, 'script_id' => Script::findSystem('winget-upgrade')->id, 'queued_by' => $this->user->id]);
    $pending->cancel();
    runningCommand($this->device, 'winget-uninstall', $this->user)->markAsRunning();

    expect(inventoryCommandsFor($this->device))->toBe(0);
});

it('queues only one refresh when several changes finish before it runs', function (): void {
    runningCommand($this->device, 'winget-upgrade', $this->user)->markAsCompleted('OK', 0);
    runningCommand($this->device, 'winget-uninstall', $this->user)->markAsCompleted('OK', 0);

    expect(inventoryCommandsFor($this->device))->toBe(1);
});
