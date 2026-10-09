<?php

declare(strict_types=1);

use App\Actions\DeletePath\QueueDeletePath;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Enums\DeleteMode;
use App\Exceptions\PathCannotBeDeleted;
use App\Livewire\Devices\DeletePath;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Storage;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceDiskScan;
use App\Models\Script;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'DESKTOP-TF6VC1D', 'agent_version' => '0.9.0']);
});

/**
 * The fixture scan of C:\ plus a small folder, C:\Users\anna\Small, under the typing threshold.
 */
function deletePathScan(Device $device): DeviceDiskScan
{
    $data = diskScanFixture();
    $data['nodes'][] = [6, 'Small', 1024 * 1024, 1024 * 1024, 3, 0, 0];

    return DeviceDiskScan::factory()->create(['device_id' => $device->id, 'root' => 'C:\\', 'data' => $data, 'scanned_at' => now()->subHour()]);
}

function queuedDelete(Device $device): DeviceCommand
{
    return $device->commands()->where('script_id', Script::findSystem('remove-path')->id)->sole();
}

it('opens from a scanned folder with its size and files, and wants the name typed when it is big', function (): void {
    deletePathScan($this->device);

    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path', path: 'C:\\Users\\anna\\Downloads', kind: 'folder')
        ->assertSet('showModal', true)
        ->assertSet('isPathFixed', true)
        ->assertSeeHtml('data-delete-path-measured')
        ->assertSee('C:\\Users\\anna\\Downloads')
        ->assertSee('18.0 GB')
        ->assertSee('900')
        ->assertSee('Permanently delete 18.0 GB from DESKTOP-TF6VC1D')
        ->assertSeeHtml('data-delete-path-confirmation')
        ->assertSee('Type Downloads to confirm');
});

it('shows a scanned file with when it was last modified', function (): void {
    deletePathScan($this->device);

    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path', path: 'C:\\Users\\anna\\Downloads\\windows11.iso', kind: 'file')
        ->assertSee('5.0 GB')
        ->assertSee('1 Mar 2025');
});

it('queues a small scanned folder without typing, with the path, mode and kind as parameters', function (): void {
    deletePathScan($this->device);

    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path', path: 'C:\\Users\\anna\\Small', kind: 'folder')
        ->assertDontSeeHtml('data-delete-path-confirmation')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('showModal', false)
        ->assertDispatched('command-queued')
        ->assertDispatched('toast-show');

    expect(queuedDelete($this->device))
        ->parameters->toBe(['Path' => 'C:\\Users\\anna\\Small', 'Mode' => 'delete', 'ExpectedKind' => 'folder'])
        ->queued_by->toBe($this->user->id)
        ->status->toBe(CommandStatus::Pending);
});

it('refuses until the name is typed, ignoring case', function (): void {
    deletePathScan($this->device);

    $component = Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path', path: 'C:\\Users\\anna\\Downloads', kind: 'folder')
        ->call('confirm')
        ->assertHasErrors(['confirmation' => 'required'])
        ->set('confirmation', 'Download')
        ->call('confirm')
        ->assertHasErrors('confirmation');

    expect(DeviceCommand::query()->count())->toBe(0);

    $component->set('confirmation', 'downloads')->call('confirm')->assertHasNoErrors();

    expect(queuedDelete($this->device)->parameters['Path'])->toBe('C:\\Users\\anna\\Downloads');
});

it('takes a typed path no scan measured, wants its name, and quarantines it', function (): void {
    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path')
        ->assertSet('isPathFixed', false)
        ->assertSeeHtml('data-delete-path-input')
        ->set('path', 'c:/Veeam Backup Cache/')
        ->assertSeeHtml('data-delete-path-unmeasured')
        ->set('mode', DeleteMode::Quarantine->value)
        ->assertSee('Quarantine Veeam Backup Cache on DESKTOP-TF6VC1D')
        ->call('confirm')
        ->assertHasErrors(['confirmation' => 'required'])
        ->set('confirmation', 'Veeam Backup Cache')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(queuedDelete($this->device)->parameters)->toBe(['Path' => 'C:\\Veeam Backup Cache', 'Mode' => 'quarantine', 'ExpectedKind' => 'any']);
});

it('shows a protected or malformed path refusal inline and queues nothing', function (string $path, string $message): void {
    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $this->device])
        ->dispatch('delete-path')
        ->set('path', $path)
        ->set('confirmation', 'x')
        ->call('confirm')
        ->assertHasErrors('path')
        ->assertSee($message);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'windows' => ['C:\\Windows\\Temp', 'is protected'],
    'a profile root' => ['C:\\Users\\sophie', 'is protected'],
    'a wildcard' => ['C:\\Temp\\*', 'contains a wildcard'],
    'relative' => ['Temp\\old', 'is not a full path'],
]);

it('never queues the same path twice while one is in flight', function (): void {
    $queue = app(QueueDeletePath::class);

    $first = $queue($this->device, $this->user, 'C:\\Veeam Backup Cache', DeleteMode::Delete);
    $second = $queue($this->device, $this->user, 'c:\\Veeam Backup Cache\\', DeleteMode::Quarantine);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(DeviceCommand::query()->count())->toBe(1);
});

it('refuses monitor-only and Linux devices in the policy and the action', function (Device $device): void {
    expect($this->user->can('deletePaths', $device))->toBeFalse()
        ->and(fn () => app(QueueDeletePath::class)($device, $this->user, 'C:\\Veeam Backup Cache', DeleteMode::Delete))
        ->toThrow(PathCannotBeDeleted::class);

    Livewire::actingAs($this->user)->test(DeletePath::class, ['device' => $device])
        ->call('open', 'C:\\Veeam Backup Cache', 'folder')
        ->assertForbidden();

    Livewire::actingAs($this->user)->test(Header::class, ['device' => $device])
        ->assertDontSeeHtml('data-delete-path-entry');

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'monitor only' => fn (): Device => Device::factory()->active()->windows()->monitorOnly()->create(),
    'linux' => fn (): Device => Device::factory()->active()->linux()->create(),
]);

it('answers 403 for a PC that never deletes anything', function (): void {
    $linux = Device::factory()->active()->linux()->create();

    expect(PathCannotBeDeleted::notWindows($linux)->getStatusCode())->toBe(403)
        ->and(PathCannotBeDeleted::monitorOnly($linux)->getStatusCode())->toBe(403);
});

it('offers Delete path next to Run Command and mounts the modal in the device shell', function (): void {
    Livewire::actingAs($this->user)->test(Header::class, ['device' => $this->device])
        ->assertSeeHtml('data-delete-path-entry')
        ->assertSeeInOrder(['Run Command', 'Delete path...', 'Run Script', 'Power']);

    $this->actingAs($this->user)->get(route('devices.storage', $this->device))
        ->assertSuccessful()
        ->assertSeeHtml('data-delete-path-modal');

    $this->actingAs($this->user)->get(route('devices.storage', Device::factory()->active()->linux()->create()))
        ->assertSuccessful()
        ->assertDontSeeHtml('data-delete-path-modal');
});

it('offers Delete on folders and files the guard rails allow, never on protected ones', function (): void {
    deletePathScan($this->device);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-delete-folder')
        ->assertSeeHtml('data-delete-file')
        ->assertDontSeeHtml("path: 'C:\\\\pagefile.sys'");

    Livewire::actingAs($this->user)->withQueryParams(['node' => '1.6'])->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-delete-folder')
        ->assertSeeHtml("path: 'C:\\\\Users\\\\anna\\\\Downloads', kind: 'folder'");
});

it('shows a folder being deleted as busy until its command finishes', function (): void {
    deletePathScan($this->device);
    $command = app(QueueDeletePath::class)($this->device, $this->user, 'C:\\Users\\anna\\Downloads', DeleteMode::Delete);
    $command->markAsRunning();

    $component = Livewire::actingAs($this->user)->withQueryParams(['node' => '1.6'])->test(Storage::class, ['device' => $this->device])
        ->assertSee('Deleting...');

    $command->markAsCompleted('OK: deleted', 0);

    $component->dispatch('echo-private:devices.'.$this->device->id.',CommandUpdated', ['commandId' => $command->id])
        ->assertDontSee('Deleting...');
});
