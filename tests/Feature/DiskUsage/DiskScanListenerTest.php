<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Events\DiskScanStored;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceDiskScan;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['agent_version' => '0.9.0']);
});

function runningDiskScan(Device $device, User $user, string $slug = 'disk-usage', array $parameters = ['Path' => 'C:\\', 'Depth' => '4']): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem($slug)->id,
        'status' => CommandStatus::Running,
        'parameters' => $parameters,
        'queued_by' => $user->id,
        'sent_at' => now(),
        'started_at' => now(),
    ]);
}

/**
 * The script's stdout: two OK lines, then the scan as one compact line.
 *
 * @param  array<string, mixed>  $overrides
 */
function diskScanOutput(array $overrides = []): string
{
    return "OK: scanned C:\\ to depth 4 in 81s\nOK: 91.0 GB in 207,953 files, 2 folders could not be read\n".json_encode(diskScanFixture($overrides));
}

it('stores the scan with its headline numbers when disk-usage completes', function (): void {
    Event::fake([DiskScanStored::class]);

    runningDiskScan($this->device, $this->user, parameters: ['Path' => 'C:\\', 'Depth' => '3'])->markAsCompleted(diskScanOutput(), 0);

    $stored = $this->device->diskScans()->sole();

    expect($stored)
        ->root->toBe('C:\\')
        ->depth->toBe(3)
        ->duration_ms->toBe(81234)
        ->allocated->toBe(diskScanFixture()['totals']['allocated'])
        ->files->toBe(207953)
        ->error_count->toBe(2)
        ->and($stored->scan()->tree->path(7))->toBe('C:\\Users\\anna\\Downloads');

    Event::assertDispatched(DiskScanStored::class, fn (DiskScanStored $event): bool => $event->deviceId === $this->device->id);
});

it('keeps only the newest five scans of each folder', function (): void {
    collect(range(1, 6))->each(function (int $run): void {
        $this->travel(1)->hour();
        runningDiskScan($this->device, $this->user)->markAsCompleted(diskScanOutput(['duration_ms' => $run]), 0);
    });
    runningDiskScan($this->device, $this->user, parameters: ['Path' => 'C:\\Users'])
        ->markAsCompleted(diskScanOutput(['root' => 'C:\\Users', 'nodes.0.1' => 'C:\\Users']), 0);

    expect($this->device->diskScans()->ofRoot('C:\\')->orderBy('scanned_at')->pluck('duration_ms')->all())->toBe([2, 3, 4, 5, 6])
        ->and($this->device->diskScans()->ofRoot('C:\\Users')->count())->toBe(1);
});

it('never prunes another device\'s scans', function (): void {
    $other = DeviceDiskScan::factory()->create();

    collect(range(1, 6))->each(fn () => runningDiskScan($this->device, $this->user)->markAsCompleted(diskScanOutput(), 0));

    expect(DeviceDiskScan::query()->whereKey($other->id)->exists())->toBeTrue();
});

it('stores nothing for failed runs, unreadable output and other scripts', function (string $slug, string $output, int $exitCode): void {
    runningDiskScan($this->device, $this->user, $slug)->markAsCompleted($output, $exitCode);

    expect(DeviceDiskScan::query()->count())->toBe(0);
})->with([
    'failed without json' => ['disk-usage', 'ATTENTION: the disk scan of C:\\ failed with exit code 3 and printed no result', 1],
    'agent too old' => ['disk-usage', 'ATTENTION: this agent is too old for disk scans; update the agent', 0],
    'unknown schema' => fn (): array => ['disk-usage', diskScanOutput(['schema' => 'rmm.du/2']), 0],
    'truncated json' => ['disk-usage', '{"schema":"rmm.du/1","root":"C:\\\\","nodes":[[-1', 0],
    'broken tree' => fn (): array => ['disk-usage', diskScanOutput(['nodes.3.0' => 9]), 0],
    'another script' => fn (): array => ['system-info', diskScanOutput(), 0],
]);

it('stores a scan from a run that failed but still printed one', function (bool $markFailed): void {
    $command = runningDiskScan($this->device, $this->user);

    $markFailed ? $command->markAsFailed('agent error', diskScanOutput(), 1) : $command->markAsCompleted(diskScanOutput(), 1);

    expect($this->device->diskScans()->count())->toBe(1);
})->with(['exit 1' => false, 'marked failed' => true]);

it('stores nothing for a run that timed out or was cancelled', function (): void {
    runningDiskScan($this->device, $this->user)->markAsTimedOut(diskScanOutput(), 1);

    expect(DeviceDiskScan::query()->count())->toBe(0);
});

it('never stores a scan for a monitor-only device', function (): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    runningDiskScan($device, $this->user)->markAsCompleted(diskScanOutput(), 0);

    expect(DeviceDiskScan::query()->count())->toBe(0);
});
