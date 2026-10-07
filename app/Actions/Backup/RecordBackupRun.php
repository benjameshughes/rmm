<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Enums\BackupRunStatus;
use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\DeviceCommand;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a finished backup-files run from its result line: restic exit 0 is
 * a good backup, 3 a good one that skipped unreadable files, anything else
 * (a timeout or a script that died without a result included) a failure.
 * A PC the script skipped for having no credentials records nothing. A run
 * that saved a snapshot adds it to the snapshot list straight away.
 */
final class RecordBackupRun
{
    public function __construct(
        private readonly SyncBackupAlert $syncBackupAlert,
    ) {}

    public function __invoke(DeviceCommand $command): ?DeviceBackup
    {
        $result = $command->resultJson() ?? [];

        if (($result['status'] ?? null) === 'skipped' || ! $command->device->hasBackupCredentials) {
            return null;
        }

        $status = BackupRunStatus::fromExitCode($command->status === CommandStatus::TimedOut ? null : $command->exit_code);
        $finishedAt = $command->completed_at ?? now();

        $backup = DB::transaction(function () use ($command, $result, $status, $finishedAt): DeviceBackup {
            $backup = DeviceBackup::query()->updateOrCreate(['device_command_id' => $command->id], [
                'device_id' => $command->device_id,
                'status' => $status,
                'exit_code' => $command->exit_code,
                'snapshot_id' => $this->snapshotId($result),
                'files_new' => $this->count($result, 'files_new'),
                'files_changed' => $this->count($result, 'files_changed'),
                'files_unmodified' => $this->count($result, 'files_unmodified'),
                'data_added' => $this->count($result, 'data_added'),
                'total_bytes_processed' => $this->count($result, 'total_bytes_processed'),
                'duration_seconds' => is_numeric($result['total_duration'] ?? null) ? (float) $result['total_duration'] : null,
                'errors' => $this->errors($command, $result, $status),
                'finished_at' => $finishedAt,
            ]);

            $command->device->forceFill([
                'last_backup_at' => $finishedAt,
                'last_backup_status' => $status,
                'last_good_backup_at' => $status->isGood() ? $finishedAt : $command->device->last_good_backup_at,
            ])->save();

            $this->addSnapshot($command->device, $backup, $result);

            return $backup;
        });

        ($this->syncBackupAlert)($command->device);

        return $backup;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function snapshotId(array $result): ?string
    {
        $snapshotId = $result['snapshot_id'] ?? null;

        return is_string($snapshotId) && preg_match('/^[0-9a-f]{8,64}$/', $snapshotId) === 1 ? $snapshotId : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function count(array $result, string $key): ?int
    {
        return is_numeric($result[$key] ?? null) ? (int) $result[$key] : null;
    }

    /**
     * restic's own errors, or for a run that died without them, the script's verdict line.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function errors(DeviceCommand $command, array $result, BackupRunStatus $status): array
    {
        $errors = collect(Arr::wrap($result['errors'] ?? []))
            ->filter(fn (mixed $error): bool => is_string($error) && trim($error) !== '')
            ->map(fn (string $error): string => Str::limit(trim($error), config('backup.error_max_length')))
            ->take(config('backup.errors_kept'))
            ->values();

        return $errors->isEmpty() && $status === BackupRunStatus::Failed ? [$command->summaryLine()] : $errors->all();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function addSnapshot(Device $device, DeviceBackup $backup, array $result): void
    {
        if ($backup->snapshot_id === null) {
            return;
        }

        $device->backupSnapshots()->updateOrCreate(['snapshot_id' => $backup->snapshot_id], [
            'short_id' => $backup->shortSnapshotId(),
            'taken_at' => $backup->finished_at,
            'paths' => collect(Arr::wrap($result['sources'] ?? []))->filter(fn (mixed $path): bool => is_string($path))->values()->all(),
            'files' => $backup->files_new === null ? null : $backup->files_new + $backup->files_changed + $backup->files_unmodified,
            'bytes' => $backup->total_bytes_processed,
            'bytes_added' => $backup->data_added,
        ]);
    }
}
