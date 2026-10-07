<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\BackupRunStatus;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Livewire\Dashboard;
use App\Livewire\Devices\Backups;
use App\Livewire\Devices\RestoreBackup;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\DeviceBackupSnapshot;
use App\Models\DeviceCommand;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->withBackupCredentials(setHoursAgo: 72)->create(['hostname' => 'ACCOUNTS-PC', 'agent_version' => '0.7.1', 'last_seen' => now()]);
});

function queuedBackupCommand(Device $device, BackupScript $script): DeviceCommand
{
    return DeviceCommand::query()->where('device_id', $device->id)->whereRelation('script', 'slug', $script->value)->sole();
}

it('renders the state, recent runs and snapshots at its own route', function (): void {
    $this->device->forceFill(['last_good_backup_at' => now()->subHours(3), 'last_backup_at' => now()->subHours(3), 'last_backup_status' => BackupRunStatus::Succeeded])->save();
    DeviceBackup::factory()->create(['device_id' => $this->device->id, 'finished_at' => now()->subHours(3), 'snapshot_id' => str_repeat('c0ffee00', 8)]);
    DeviceBackup::factory()->failed(10)->create(['device_id' => $this->device->id, 'finished_at' => now()->subDays(2), 'errors' => ['the repository does not exist']]);
    DeviceBackupSnapshot::factory()->create(['device_id' => $this->device->id, 'short_id' => 'beefcafe', 'paths' => ['C:\\Users\\anna', 'C:\\Users\\ben']]);

    $this->actingAs($this->user)
        ->get(route('devices.backups', $this->device))
        ->assertSuccessful()
        ->assertSee('ACCOUNTS-PC · Backups', false)
        ->assertSee('data-backup-state="healthy"', false)
        ->assertSee('Last good backup 3 hours ago')
        ->assertSeeInOrder(['c0ffee00', 'the repository does not exist'])
        ->assertSee('beefcafe')
        ->assertSee('anna, ben')
        ->assertSee('Back up now')
        ->assertSee('Refresh snapshots')
        ->assertSee('data-restore-snapshot', false);
});

it('says what is wrong when the PC is overdue', function (): void {
    $this->actingAs($this->user)
        ->get(route('devices.backups', $this->device))
        ->assertSee('data-backup-state="stale"', false)
        ->assertSee('No backup since its credentials were set 3 days ago');
});

it('asks for credentials and hides the buttons on a PC that is not set up', function (): void {
    $bare = Device::factory()->active()->windows()->create();

    $this->actingAs($this->user)
        ->get(route('devices.backups', $bare))
        ->assertSee('data-backup-state="not_configured"', false)
        ->assertSee('Enable backups below', false)
        ->assertDontSee('Back up now');

    Livewire::actingAs($this->user)->test(Backups::class, ['device' => $bare])
        ->call('backUpNow')
        ->assertStatus(422);
});

it('only backs up Windows PCs', function (): void {
    $this->actingAs($this->user)
        ->get(route('devices.backups', Device::factory()->active()->linux()->create()))
        ->assertSee('File backups are only for Windows PCs.');
});

it('backs up now with no start delay, and refreshes snapshots', function (): void {
    Livewire::actingAs($this->user)->test(Backups::class, ['device' => $this->device])
        ->call('backUpNow')
        ->assertDispatched('command-queued')
        ->call('refreshSnapshots')
        ->assertSee('Queued...');

    expect(queuedBackupCommand($this->device, BackupScript::BackUp))
        ->status->toBe(CommandStatus::Pending)
        ->parameters->toBe(['StaggerMinutes' => '0', 'UploadLimitKiB' => '0'])
        ->and(queuedBackupCommand($this->device, BackupScript::ListSnapshots)->parameters)->toBeNull();
});

it('refuses to queue backups on a monitor-only device', function (): void {
    $this->device->forceFill(['is_monitor_only' => true])->save();

    Livewire::actingAs($this->user)->test(Backups::class, ['device' => $this->device])
        ->call('backUpNow')
        ->assertForbidden();
});

it('restores a snapshot onto this PC', function (): void {
    $snapshot = DeviceBackupSnapshot::factory()->create(['device_id' => $this->device->id]);

    Livewire::actingAs($this->user)->test(RestoreBackup::class, ['device' => $this->device])
        ->call('open', $snapshot->snapshot_id)
        ->assertSet('showModal', true)
        ->assertSet('targetDeviceId', $this->device->id)
        ->set('includePath', 'C:\\Users\\anna\\Documents\\Quotes')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    expect(queuedBackupCommand($this->device, BackupScript::Restore)->parameters)->toBe([
        'SnapshotId' => $snapshot->snapshot_id,
        'IncludePath' => 'C:\\Users\\anna\\Documents\\Quotes',
        'SourceDevice' => (string) $this->device->id,
    ]);
});

it('restores onto another Windows PC, naming this one as the source', function (): void {
    $target = Device::factory()->active()->windows()->create(['hostname' => 'NEW-LAPTOP', 'agent_version' => '0.7.1']);

    Livewire::actingAs($this->user)->test(RestoreBackup::class, ['device' => $this->device])
        ->call('open', 'latest')
        ->assertSee('NEW-LAPTOP')
        ->set('targetDeviceId', $target->id)
        ->set('target', 'D:\\Restore\\Accounts')
        ->call('restore')
        ->assertHasNoErrors();

    expect(queuedBackupCommand($target, BackupScript::Restore)->parameters)->toBe([
        'SnapshotId' => 'latest',
        'Target' => 'D:\\Restore\\Accounts',
        'SourceDevice' => (string) $this->device->id,
    ])->and(DeviceCommand::query()->where('device_id', $this->device->id)->count())->toBe(0);
});

it('refuses a restore that is not safe to queue', function (string $field, mixed $value, string $rule): void {
    Livewire::actingAs($this->user)->test(RestoreBackup::class, ['device' => $this->device])
        ->call('open', 'latest')
        ->set($field, $value instanceof Closure ? $value() : $value)
        ->call('restore')
        ->assertHasErrors([$field => $rule]);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'a snapshot of another PC' => ['snapshotId', fn (): string => DeviceBackupSnapshot::factory()->create()->snapshot_id, 'in'],
    'a Linux target' => ['targetDeviceId', fn (): int => Device::factory()->active()->linux()->create()->id, 'in'],
    'a monitor-only target' => ['targetDeviceId', fn (): int => Device::factory()->active()->windows()->create(['is_monitor_only' => true])->id, 'in'],
    'a relative include path' => ['includePath', 'Documents\\Quotes', 'regex'],
    'a target that is not a folder path' => ['target', '\\\\server\\share', 'regex'],
    'a target with a quote' => ['target', 'C:\\Restore\\"x', 'regex'],
]);

it('flags overdue, failed and partial PCs on the devices table only', function (): void {
    $failed = Device::factory()->active()->withBackupCredentials()->create(['hostname' => 'FAILED-PC', 'last_backup_status' => BackupRunStatus::Failed, 'last_backup_at' => now()]);
    Device::factory()->active()->withBackupCredentials()->create(['hostname' => 'FINE-PC', 'last_good_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Succeeded]);
    Device::factory()->active()->windows()->create(['hostname' => 'BARE-PC']);

    $html = $this->actingAs($this->user)->get(route('devices.index'))->assertSuccessful()->getContent();

    expect(substr_count($html, 'data-backup-badge='))->toBe(2)
        ->and($html)->toContain('data-backup-badge="failed"')
        ->and($html)->toContain('data-backup-badge="stale"')
        ->and($html)->toContain('Backup &middot; none yet');

    expect($failed->backupState()->value)->toBe('failed');
});

it('lists only backup problems on the dashboard, and hides the card when there are none', function (): void {
    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertSee('data-backup-problems', false)
        ->assertSee('ACCOUNTS-PC');

    $this->device->forceFill(['last_good_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Succeeded])->save();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->assertDontSee('data-backup-problems', false);
});
