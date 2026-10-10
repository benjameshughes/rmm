<?php

declare(strict_types=1);

use App\Actions\DiskUsage\BuildDiskFolderRows;
use App\Actions\DiskUsage\FindDiskCulprits;
use App\Actions\DiskUsage\QueueDiskScan;
use App\Actions\Script\SyncSystemScripts;
use App\DTOs\DiskUsage\DiskCulprit;
use App\DTOs\DiskUsage\DiskFolderRow;
use App\DTOs\DiskUsage\DiskScan;
use App\Enums\CommandStatus;
use App\Livewire\Devices\Storage;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceDiskScan;
use App\Models\DeviceInventory;
use App\Models\Script;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'OFFICE-PC', 'agent_version' => '0.9.0']);
});

function storedDiskScan(Device $device, array $overrides = [], ?CarbonInterface $scannedAt = null): DeviceDiskScan
{
    $data = diskScanFixture($overrides);

    return DeviceDiskScan::factory()->create(['device_id' => $device->id, 'root' => $data['root'], 'data' => $data, 'scanned_at' => $scannedAt ?? now()->subHour()]);
}

it('shows the top of the latest scan with the volume, space hogs, largest files and extensions', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSee('Scanned 1 hour ago.')
        ->assertSee('Scan now')
        ->assertSeeHtml('data-volume-bar')
        ->assertSeeHtml('data-flux-progress')
        ->assertSeeHtml('wire:key="usage-bar-')
        ->assertSeeHtml('var(--color-blue-600)')
        ->assertSee('100.0 GB free of 256.0 GB')
        ->assertSeeInOrder(['Users', 'Windows', 'Program Files', '$Recycle.Bin', 'Documents and Settings', '(other)'])
        ->assertSeeInOrder(['Space hogs', 'Outlook data', 'anna', '25.0 GB'])
        ->assertSee('C:\\Users\\anna\\AppData\\Local\\Microsoft\\Outlook\\anna.ost')
        ->assertSeeHtml('data-extension=".ost"')
        ->assertSee('2 folders could not be read.')
        ->assertDontSeeHtml('data-storage-empty');
});

it('drills into a folder by its index path, with breadcrumbs back up', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->withQueryParams(['node' => '1.6'])->test(Storage::class, ['device' => $this->device])
        ->assertSeeInOrder(['C:\\', 'Users', 'anna'])
        ->assertSeeHtml('data-folder="Downloads"')
        ->assertSeeHtml('data-folder="AppData"')
        ->assertDontSeeHtml('data-folder="Windows"')
        ->call('open', '1.6.8')
        ->assertSet('node', '1.6.8')
        ->assertSeeHtml('data-folder="Local"')
        ->assertSeeHtml('node=1.6')
        ->call('open', '')
        ->assertSeeHtml('data-folder="Windows"');
});

it('falls back to the deepest folder an outdated index path still reaches', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->withQueryParams(['node' => '1.42.8'])->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-folder="anna"');
});

it('badges rolled-up, unfollowed, cloud and unreadable folders', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-flag="Other"')
        ->assertSeeHtml('data-flag="LinkNotFollowed"')
        ->call('open', '4')
        ->assertSeeHtml('data-flag="AccessDenied"')
        ->call('open', '1.6')
        ->assertSeeHtml('data-flag="Cloud"')
        ->assertDontSeeHtml('data-flag="Kept"');
});

it('shows how much each folder grew or shrank since the previous scan, matched by path', function (): void {
    $gigabyte = 1024 ** 3;
    storedDiskScan($this->device, ['nodes.2.2' => 28 * $gigabyte, 'nodes.4.1' => 'Old Programs'], now()->subDays(2));
    storedDiskScan($this->device, ['nodes.2.2' => 30 * $gigabyte, 'nodes.3.2' => 2 * $gigabyte]);

    $scans = $this->device->diskScans()->latest('scanned_at')->get()->map(fn (DeviceDiskScan $stored): DiskScan => $stored->scan());
    $rows = app(BuildDiskFolderRows::class)($scans[0], $scans[1], 0)->keyBy(fn (DiskFolderRow $row): string => $row->name);

    expect($rows['Windows']->change)->toBe(2 * $gigabyte)
        ->and($rows['Windows']->changeForHumans())->toBe('+2.0 GB')
        ->and($rows['Windows']->changeColor())->toBe('red')
        ->and($rows['Program Files']->isNew)->toBeTrue()
        ->and($rows['$Recycle.Bin']->change)->toBe(0)
        ->and($rows['$Recycle.Bin']->changeForHumans())->toBeNull()
        ->and($rows['(other)']->change)->toBeNull()
        ->and($rows['(other)']->isNew)->toBeFalse();

    $shrunk = new DiskFolderRow(index: 1, indexPath: '1', name: 'x', path: 'C:\\x', allocated: 1, files: 1, percentOfParent: 1.0, badges: [], change: -1048576, isNew: false, canOpen: false, canScanDeeper: false);
    expect($shrunk->changeForHumans())->toBe('-1.0 MB')->and($shrunk->changeColor())->toBe('green');

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSee('Compared with the scan 2 days ago.')
        ->assertSeeHtml('data-change="+2.0 GB"')
        ->assertSeeHtml('data-change="New"');
});

it('shows no growth without a previous scan of the same folder', function (): void {
    $rows = app(BuildDiskFolderRows::class)(DiskScan::fromArray(diskScanFixture()), null, 0);

    expect($rows->every(fn (DiskFolderRow $row): bool => $row->change === null && ! $row->isNew))->toBeTrue();
});

it('names space hogs per user, recycle bins by the profile with that SID, and root files', function (): void {
    $culprits = app(FindDiskCulprits::class)(DiskScan::fromArray(diskScanFixture()), ['S-1-5-21-111-222-333-1001' => 'anna'])
        ->map(fn (DiskCulprit $culprit): array => [$culprit->label, $culprit->owner, $culprit->allocatedForHumans(), $culprit->indexPath])
        ->all();

    expect($culprits)->toBe([
        ['Outlook data', 'anna', '25.0 GB', '1.6.8.9.10.11'],
        ['Downloads', 'anna', '18.0 GB', '1.6.7'],
        ['Page file', null, '8.0 GB', null],
        ['Windows Update cache', null, '6.0 GB', '2.12.13'],
        ['Hibernation file', null, '6.0 GB', null],
        ['Windows Installer cache', null, '4.0 GB', '2.14'],
        ['Recycle Bin', 'anna', '2.0 GB', '3.15'],
    ]);
});

it('shows the SID when no profile is known for a recycle bin', function (): void {
    $recycleBin = app(FindDiskCulprits::class)(DiskScan::fromArray(diskScanFixture()))->firstWhere('label', 'Recycle Bin');

    expect($recycleBin->owner)->toBe('S-1-5-21-111-222-333-1001');
});

it('maps recycle bin SIDs to users from the latest system inventory', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id, 'data' => ['users' => ['profiles' => [['path' => 'C:\\Users\\sarah', 'sid' => 's-1-5-21-111-222-333-1001']]]]]);
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeInOrder(['Recycle Bin', 'sarah']);
});

it('leaves root files out of a scan of a folder', function (): void {
    $culprits = app(FindDiskCulprits::class)(DiskScan::fromArray(diskScanFixture(['root' => 'C:\\Users', 'nodes.0.1' => 'C:\\Users'])));

    expect($culprits->pluck('label'))->not->toContain('Page file');
});

it('queues a scan of the shown drive with its parameters', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->call('scanNow')
        ->assertHasNoErrors()
        ->assertDispatched('command-queued')
        ->assertSeeHtml('data-run-button-busy')
        ->assertDontSeeHtml('data-scan-folder');

    expect($this->device->commands()->sole())
        ->script_id->toBe(Script::findSystem('disk-usage')->id)
        ->parameters->toBe(['Path' => 'C:\\', 'Depth' => '4'])
        ->queued_by->toBe($this->user->id);
});

it('queues the default drive from the empty state and never twice at once', function (): void {
    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-storage-empty')
        ->call('scanNow')
        ->call('scanNow');

    expect($this->device->commands()->sole()->parameters)->toBe(['Path' => 'C:\\', 'Depth' => '4']);
});

it('offers a scan of a folder the scan stopped above and queues it by its path', function (): void {
    storedDiskScan($this->device);

    Livewire::actingAs($this->user)->withQueryParams(['node' => '4'])->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-scan-folder')
        ->call('scanFolder', '4.17');

    expect($this->device->commands()->sole()->parameters)->toBe(['Path' => 'C:\\Program Files\\WindowsApps', 'Depth' => '4']);
});

it('keeps the open folder by its path when a new scan lands', function (): void {
    storedDiskScan($this->device, scannedAt: now()->subDay());

    $component = Livewire::actingAs($this->user)->withQueryParams(['node' => '1.6'])->test(Storage::class, ['device' => $this->device]);

    $reordered = diskScanFixture();
    $reordered['nodes'][6][1] = 'zara';
    $reordered['nodes'][] = [1, 'anna', 1024, 1024, 1, 0, 0];
    storedDiskScan($this->device, $reordered);

    $component->dispatch('echo-private:devices.'.$this->device->id.',DiskScanStored', ['deviceId' => $this->device->id])
        ->assertSet('node', '1.19');
});

it('switches between scanned folders', function (): void {
    storedDiskScan($this->device, scannedAt: now()->subDay());
    storedDiskScan($this->device, ['root' => 'C:\\Users', 'nodes.0.1' => 'C:\\Users']);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-root-select')
        ->assertDontSeeHtml('data-volume-bar')
        ->set('root', 'C:\\')
        ->assertSeeHtml('data-volume-bar');
});

it('hides scanning from users who cannot run commands', function (): void {
    storedDiskScan($this->device);
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'runCommands' ? false : null);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-scan-now')
        ->assertDontSeeHtml('data-scan-folder')
        ->call('scanNow')
        ->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('explains that only Windows PCs are scanned', function (): void {
    $linux = Device::factory()->active()->linux()->create();

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $linux])
        ->assertSee('Disk scans run on Windows PCs that accept commands.')
        ->assertDontSeeHtml('data-scan-now');
});

it('serves the tab at its own route', function (): void {
    storedDiskScan($this->device);

    $this->actingAs($this->user)->get(route('devices.storage', [$this->device, 'node' => '1']))
        ->assertSuccessful()
        ->assertSee('OFFICE-PC · Storage')
        ->assertSee('anna');
});

it('shows an in-flight scan as running', function (): void {
    DeviceCommand::factory()->create([
        'device_id' => $this->device->id,
        'script_id' => Script::findSystem('disk-usage')->id,
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)->test(Storage::class, ['device' => $this->device])
        ->assertSeeHtml('data-run-button-busy');
});

it('refuses to queue a scan of a bad folder, a bad depth or a linux device', function (string $path, int $depth, bool $isLinux): void {
    $device = $isLinux ? Device::factory()->active()->linux()->create(['agent_version' => '0.9.0']) : $this->device;

    expect(fn () => app(QueueDiskScan::class)($device, $this->user, $path, $depth))->toThrow(ValidationException::class);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'no backslash' => ['C:', 4, false],
    'quote' => ['C:\\a" & calc', 4, false],
    'too deep' => ['C:\\', 7, false],
    'too shallow' => ['C:\\', 0, false],
    'linux' => ['C:\\', 4, true],
]);
