<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\AlertMetric;
use App\Enums\BackupRunStatus;
use App\Enums\BackupScript;
use App\Enums\CommandStatus;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\DeviceBackupSnapshot;
use App\Models\DeviceCommand;
use App\Models\Script;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->device = Device::factory()->active()->withBackupCredentials(setHoursAgo: 72)->create();
});

function runningBackupCommand(Device $device, BackupScript $script = BackupScript::BackUp): DeviceCommand
{
    return DeviceCommand::factory()->create([
        'device_id' => $device->id,
        'script_id' => Script::findSystem($script->value)->id,
        'status' => CommandStatus::Running,
        'sent_at' => now(),
        'started_at' => now(),
    ]);
}

/**
 * The script's output: a verdict line, then the JSON result line.
 *
 * @param  array<string, mixed>  $result
 */
function backupOutput(string $verdict, array $result): string
{
    return "{$verdict}\r\nProfiles: C:\\Users\\anna\r\n".json_encode($result)."\r\n";
}

function succeededBackupResult(array $overrides = []): array
{
    return [
        'status' => 'ok',
        'exit_code' => 0,
        'snapshot_id' => str_repeat('ab12', 16),
        'files_new' => 12,
        'files_changed' => 3,
        'files_unmodified' => 40211,
        'data_added' => 52_428_800,
        'total_bytes_processed' => 21_474_836_480,
        'total_duration' => 312.45,
        'errors' => [],
        'restic_version' => '0.19.1',
        'sources' => ['C:\\Users\\anna', 'C:\\Users\\ben'],
        ...$overrides,
    ];
}

it('records a good backup, stamps the device and lists the new snapshot', function (): void {
    runningBackupCommand($this->device)->markAsCompleted(backupOutput('OK: backed up 2 user profiles', succeededBackupResult()), 0);

    $backup = DeviceBackup::query()->sole();
    $device = $this->device->fresh();

    expect($backup)
        ->status->toBe(BackupRunStatus::Succeeded)
        ->exit_code->toBe(0)
        ->snapshot_id->toBe(str_repeat('ab12', 16))
        ->files_new->toBe(12)
        ->files_changed->toBe(3)
        ->files_unmodified->toBe(40211)
        ->data_added->toBe(52_428_800)
        ->total_bytes_processed->toBe(21_474_836_480)
        ->duration_seconds->toBe(312.45)
        ->errors->toBe([])
        ->and($device->last_backup_status)->toBe(BackupRunStatus::Succeeded)
        ->and($device->last_backup_at)->not->toBeNull()
        ->and($device->last_good_backup_at->equalTo($device->last_backup_at))->toBeTrue()
        ->and(DeviceBackupSnapshot::query()->sole())
        ->short_id->toBe('ab12ab12')
        ->paths->toBe(['C:\\Users\\anna', 'C:\\Users\\ben'])
        ->files->toBe(40226)
        ->bytes->toBe(21_474_836_480);
});

it('counts exit 3 as a good backup that skipped files, keeping the unreadable ones', function (): void {
    runningBackupCommand($this->device)->markAsCompleted(backupOutput('ATTENTION: backed up, but 2 files could not be read', succeededBackupResult([
        'status' => 'partial',
        'exit_code' => 3,
        'errors' => ['C:\\Users\\anna\\locked.pst: The process cannot access the file', 'C:\\Users\\ben\\x.db: Access is denied'],
    ])), 3);

    $device = $this->device->fresh();

    expect(DeviceBackup::query()->sole())
        ->status->toBe(BackupRunStatus::Partial)
        ->errors->toHaveCount(2)
        ->and($device->last_backup_status)->toBe(BackupRunStatus::Partial)
        ->and($device->last_good_backup_at)->not->toBeNull();
});

it('records a failure without moving the last good backup', function (int $exitCode, string $verdict): void {
    $lastGood = now()->subHours(30)->startOfSecond();
    $this->device->forceFill(['last_good_backup_at' => $lastGood, 'last_backup_status' => BackupRunStatus::Succeeded])->save();

    runningBackupCommand($this->device)->markAsCompleted(backupOutput($verdict, ['status' => 'failed', 'exit_code' => $exitCode, 'errors' => ['Fatal: unable to open config file']]), $exitCode);

    $device = $this->device->fresh();

    expect(DeviceBackup::query()->sole())
        ->status->toBe(BackupRunStatus::Failed)
        ->exit_code->toBe($exitCode)
        ->snapshot_id->toBeNull()
        ->errors->toBe(['Fatal: unable to open config file'])
        ->and($device->last_backup_status)->toBe(BackupRunStatus::Failed)
        ->and($device->last_good_backup_at->equalTo($lastGood))->toBeTrue()
        ->and(DeviceBackupSnapshot::query()->count())->toBe(0);
})->with([
    'restic failed' => [1, 'ATTENTION: restic failed with exit code 1'],
    'repository missing' => [10, 'ATTENTION: the repository for pc does not exist on the backup server yet. Back up now creates it'],
    'wrong password' => [12, 'ATTENTION: the repository password is wrong'],
]);

it('records a run that timed out or died without a result line, using its verdict as the error', function (): void {
    runningBackupCommand($this->device)->markAsTimedOut('', -1);
    runningBackupCommand($this->device)->markAsCompleted("ATTENTION: could not lock C:\\ProgramData\\RMM to SYSTEM and Administrators\r\n", 1);

    $backups = DeviceBackup::query()->orderBy('id')->get();

    expect($backups->pluck('status')->all())->toBe([BackupRunStatus::Failed, BackupRunStatus::Failed])
        ->and($backups[0]->errors)->toBe(['Command timed out after '.$backups[0]->command->timeout_seconds.' seconds'])
        ->and($backups[1]->errors)->toBe(['ATTENTION: could not lock C:\\ProgramData\\RMM to SYSTEM and Administrators']);
});

it('records nothing for a PC the script skipped, a cancelled run or one still running', function (): void {
    $bare = Device::factory()->active()->windows()->create();

    runningBackupCommand($bare)->markAsCompleted(backupOutput('OK: backups are not set up for this PC', ['status' => 'skipped', 'exit_code' => 0]), 0);
    DeviceCommand::factory()->pending()->create(['device_id' => $this->device->id, 'script_id' => Script::findSystem('backup-files')->id])->cancel();
    runningBackupCommand($this->device)->markAsRunning();

    expect(DeviceBackup::query()->count())->toBe(0)
        ->and($bare->fresh()->last_backup_at)->toBeNull();
});

it('records a run once even if its command is saved again', function (): void {
    $command = runningBackupCommand($this->device);
    $command->markAsCompleted(backupOutput('OK', succeededBackupResult()), 0);
    $command->update(['status' => CommandStatus::Completed]);
    $command->touch();

    expect(DeviceBackup::query()->count())->toBe(1);
});

it('ignores other scripts finishing', function (): void {
    DeviceCommand::factory()->create(['device_id' => $this->device->id, 'script_id' => Script::findSystem('restart')->id, 'status' => CommandStatus::Running])
        ->markAsCompleted(json_encode(succeededBackupResult()), 0);

    expect(DeviceBackup::query()->count())->toBe(0);
});

it('resolves the backup alert once a good backup lands and raises it when one fails', function (): void {
    runningBackupCommand($this->device)->markAsCompleted(backupOutput('ATTENTION: restic failed', ['status' => 'failed', 'exit_code' => 1]), 1);

    $alert = Alert::query()->where('metric', AlertMetric::BackupOverdue)->sole();
    expect($alert->message)->toContain('last backup failed');

    runningBackupCommand($this->device)->markAsCompleted(backupOutput('OK', succeededBackupResult()), 0);

    expect($alert->fresh()->resolved_at)->not->toBeNull();
});

it('replaces the snapshot list from a snapshot listing, newest restic times included', function (): void {
    DeviceBackupSnapshot::factory()->create(['device_id' => $this->device->id, 'short_id' => 'gone0000']);

    $listing = [
        'status' => 'ok',
        'exit_code' => 0,
        'snapshots' => [
            ['id' => str_repeat('1a', 32), 'short_id' => '1a1a1a1a', 'time' => '2026-10-06T01:15:42.123456789+01:00', 'paths' => ['C:\\Users\\anna'], 'files' => 4000, 'bytes' => 9000, 'added' => 100],
            ['id' => str_repeat('2b', 32), 'short_id' => '2b2b2b2b', 'time' => '2026-10-07T01:15:42.5+01:00', 'paths' => ['C:\\Users\\anna', 'C:\\Users\\ben'], 'files' => null, 'bytes' => null, 'added' => null],
            ['id' => 'not-hex', 'time' => '2026-10-07T01:15:42Z'],
            ['id' => str_repeat('3c', 32), 'time' => 'yesterday-ish'],
        ],
    ];

    runningBackupCommand($this->device, BackupScript::ListSnapshots)->markAsCompleted("OK: 2 backup snapshots\r\n".json_encode($listing), 0);

    $snapshots = $this->device->backupSnapshots()->orderBy('taken_at')->get();

    expect($snapshots->pluck('short_id')->all())->toBe(['1a1a1a1a', '2b2b2b2b'])
        ->and($snapshots[0]->taken_at->toIso8601String())->toBe('2026-10-06T00:15:42+00:00')
        ->and($snapshots[0]->files)->toBe(4000)
        ->and($snapshots[1]->paths)->toBe(['C:\\Users\\anna', 'C:\\Users\\ben'])
        ->and($this->device->fresh()->backup_snapshots_listed_at)->not->toBeNull()
        ->and(DeviceBackup::query()->count())->toBe(0);
});

it('keeps the snapshot list when a listing fails', function (): void {
    DeviceBackupSnapshot::factory()->create(['device_id' => $this->device->id]);

    runningBackupCommand($this->device, BackupScript::ListSnapshots)->markAsCompleted("ATTENTION: restic failed\r\n".json_encode(['status' => 'failed', 'exit_code' => 1]), 1);

    expect($this->device->backupSnapshots()->count())->toBe(1)
        ->and($this->device->fresh()->backup_snapshots_listed_at)->toBeNull();
});
