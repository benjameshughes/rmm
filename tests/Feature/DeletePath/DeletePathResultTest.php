<?php

declare(strict_types=1);

use App\Actions\DeletePath\QueueDeletePath;
use App\Actions\Script\SyncSystemScripts;
use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Enums\DeleteMode;
use App\Enums\PathKind;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;
use App\Models\Script;
use App\Models\User;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'DESKTOP-TF6VC1D', 'agent_version' => '0.9.0']);
});

function runningDelete(Device $device, User $user, string $path, DeleteMode $mode = DeleteMode::Delete): DeviceCommand
{
    $command = app(QueueDeletePath::class)($device, $user, $path, $mode, PathKind::Folder);
    $command->markAsRunning();

    return $command;
}

/**
 * @param  array<string, mixed>  $result
 */
function removePathOutput(array $result): string
{
    return "OK: deleted\n".json_encode([
        'schema' => 'rmm.remove-path/1',
        'path' => 'C:\\Veeam Backup Cache',
        'mode' => 'delete',
        'kind' => 'folder',
        'removed' => true,
        'bytes' => 111_400_000_000,
        'files' => 1200,
        'failed' => [],
        'quarantined_to' => null,
        'quarantine_folder' => null,
        'error' => null,
        ...$result,
    ]);
}

function diskScansQueued(Device $device): array
{
    return $device->commands()->where('script_id', Script::findSystem('disk-usage')->id)->get()->pluck('parameters')->all();
}

it('audits a finished delete with the path, mode and bytes, and rescans its drive', function (): void {
    runningDelete($this->device, $this->user, 'C:\\Veeam Backup Cache')->markAsCompleted(removePathOutput([]), 0);

    $audit = AuditLog::query()->where('action', AuditAction::PathDeleted)->sole();

    expect($audit)
        ->user_id->toBe($this->user->id)
        ->subject_id->toBe($this->device->id)
        ->and($audit->properties)->toMatchArray([
            'label' => 'C:\\Veeam Backup Cache on DESKTOP-TF6VC1D',
            'path' => 'C:\\Veeam Backup Cache',
            'mode' => 'delete',
            'bytes' => 111_400_000_000,
            'files' => 1200,
            'failed' => 0,
        ])
        ->and(diskScansQueued($this->device))->toBe([['Path' => 'C:\\', 'Depth' => '4']])
        ->and(DeviceQuarantine::query()->count())->toBe(0);
});

it('takes the path and mode from the command, never from what the PC printed', function (): void {
    runningDelete($this->device, $this->user, 'D:\\Old Backups')
        ->markAsCompleted(removePathOutput(['path' => 'C:\\Windows', 'mode' => 'quarantine', 'quarantined_to' => 'C:\\x', 'quarantine_folder' => '20261009T101500Z-0a1b2c3d']), 0);

    expect(AuditLog::query()->where('action', AuditAction::PathDeleted)->sole()->properties['path'])->toBe('D:\\Old Backups')
        ->and(DeviceQuarantine::query()->count())->toBe(0)
        ->and(diskScansQueued($this->device))->toBe([['Path' => 'D:\\', 'Depth' => '4']]);
});

it('still records a delete that hit locked files', function (): void {
    runningDelete($this->device, $this->user, 'C:\\Veeam Backup Cache')
        ->markAsCompleted(removePathOutput(['removed' => false, 'bytes' => 5_000, 'files' => 3, 'failed' => ['C:\\Veeam Backup Cache\\locked.vbk']]), 1);

    expect(AuditLog::query()->where('action', AuditAction::PathDeleted)->sole()->properties)
        ->toMatchArray(['bytes' => 5_000, 'files' => 3, 'failed' => 1]);
});

it('records nothing when the PC removed nothing', function (string $output): void {
    runningDelete($this->device, $this->user, 'C:\\Veeam Backup Cache')->markAsCompleted($output, 1);

    expect(AuditLog::query()->whereIn('action', [AuditAction::PathDeleted, AuditAction::PathQuarantined])->count())->toBe(0)
        ->and(diskScansQueued($this->device))->toBe([]);
})->with([
    'refused' => fn (): string => "ATTENTION: no\n".json_encode(['schema' => 'rmm.remove-path/1', 'removed' => false, 'bytes' => 0, 'files' => 0, 'failed' => [], 'quarantined_to' => null, 'error' => 'no']),
    'no json' => fn (): string => 'ATTENTION: PowerShell fell over',
    'another schema' => fn (): string => json_encode(['schema' => 'rmm.du/1', 'bytes' => 9]),
]);

it('records a quarantine with its folder and purge date', function (): void {
    $this->freezeSecond();

    runningDelete($this->device, $this->user, 'C:\\Veeam Backup Cache', DeleteMode::Quarantine)->markAsCompleted(removePathOutput([
        'mode' => 'quarantine',
        'quarantined_to' => 'C:\\ProgramData\\RMM\\Quarantine\\20261009T101500Z-0a1b2c3d\\Veeam Backup Cache',
        'quarantine_folder' => '20261009T101500Z-0a1b2c3d',
    ]), 0);

    expect(DeviceQuarantine::query()->sole())
        ->device_id->toBe($this->device->id)
        ->path->toBe('C:\\Veeam Backup Cache')
        ->kind->toBe(PathKind::Folder)
        ->folder->toBe('20261009T101500Z-0a1b2c3d')
        ->bytes->toBe(111_400_000_000)
        ->purge_after->toEqual(now()->addDays(7))
        ->purged_at->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::PathQuarantined)->sole()->properties['mode'])->toBe('quarantine');
});

it('ignores a quarantine folder name that is not one the script makes', function (): void {
    runningDelete($this->device, $this->user, 'C:\\Veeam Backup Cache', DeleteMode::Quarantine)->markAsCompleted(removePathOutput([
        'quarantined_to' => 'C:\\Windows',
        'quarantine_folder' => '..\\..\\Windows',
    ]), 0);

    expect(DeviceQuarantine::query()->count())->toBe(0);
});

it('marks the quarantined items a purge removed and rescans', function (): void {
    $purged = DeviceQuarantine::factory()->due()->create(['device_id' => $this->device->id, 'folder' => '20261001T101500Z-0a1b2c3d']);
    $kept = DeviceQuarantine::factory()->create(['device_id' => $this->device->id, 'folder' => '20261008T101500Z-0a1b2c3d']);
    $command = DeviceCommand::factory()->create([
        'device_id' => $this->device->id,
        'script_id' => Script::findSystem('purge-quarantine')->id,
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
        'parameters' => ['Days' => '7'],
    ]);

    $command->markAsCompleted("OK: purged 1\n".json_encode([
        'schema' => 'rmm.purge-quarantine/1',
        'purged' => [['folder' => '20261001T101500Z-0a1b2c3d', 'bytes' => 1, 'files' => 1], ['folder' => 'not-ours', 'bytes' => 1, 'files' => 1]],
        'failed' => [],
        'kept' => 1,
    ]), 0);

    expect($purged->fresh()->purged_at)->not->toBeNull()
        ->and($kept->fresh()->purged_at)->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::QuarantinePurged)->sole()->properties['path'])->toBe('C:\\Veeam Backup Cache')
        ->and(diskScansQueued($this->device))->toBe([['Path' => 'C:\\', 'Depth' => '4']]);
});

it('marks a restored item restored', function (): void {
    $quarantine = DeviceQuarantine::factory()->create(['device_id' => $this->device->id, 'folder' => '20261008T101500Z-0a1b2c3d']);
    $command = DeviceCommand::factory()->create([
        'device_id' => $this->device->id,
        'script_id' => Script::findSystem('restore-quarantine')->id,
        'status' => CommandStatus::Running,
        'queued_by' => $this->user->id,
        'parameters' => ['Folder' => $quarantine->folder, 'Path' => $quarantine->path],
    ]);

    $command->markAsCompleted('OK: restored'."\n".json_encode(['schema' => 'rmm.restore-quarantine/1', 'folder' => $quarantine->folder, 'path' => $quarantine->path, 'restored' => true, 'error' => null]), 0);

    expect($quarantine->fresh()->restored_at)->not->toBeNull()
        ->and($quarantine->fresh()->isHeld())->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditAction::QuarantineRestored)->count())->toBe(1);
});
