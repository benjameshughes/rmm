<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\DiskUsage\RescanDrive;
use App\Enums\DeleteMode;
use App\Enums\PathKind;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;

/**
 * Records what a finished remove-path run did: an audit entry with the
 * path, mode and bytes, the quarantine it made, and a fresh scan of the
 * drive. The path and mode are the command's own parameters; only the
 * counts and the quarantine folder come from the PC's JSON line. A run
 * that removed nothing changes nothing.
 */
final class StoreDeletedPath
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly RescanDrive $rescanDrive,
    ) {}

    public function __invoke(DeviceCommand $command): void
    {
        $result = $command->resultJson();
        $mode = DeleteMode::tryFrom((string) ($command->parameters['Mode'] ?? ''));
        $path = $command->parameters['Path'] ?? null;
        $bytes = is_int($result['bytes'] ?? null) ? $result['bytes'] : 0;
        $files = is_int($result['files'] ?? null) ? $result['files'] : 0;
        $quarantinedTo = is_string($result['quarantined_to'] ?? null) ? $result['quarantined_to'] : null;

        if (($result['schema'] ?? null) !== config('devices.delete_path.schema') || $mode === null || ! is_string($path)) {
            return;
        }

        if ($bytes === 0 && $files === 0 && $quarantinedTo === null) {
            return;
        }

        ($this->recordAuditEvent)($mode->auditAction(), $command->device, [
            'label' => "{$path} on {$command->device->hostname}",
            'path' => $path,
            'mode' => $mode->value,
            'bytes' => $bytes,
            'files' => $files,
            'failed' => count(is_array($result['failed'] ?? null) ? $result['failed'] : []),
            'command_id' => $command->id,
        ], actor: $command->queuedBy);

        if ($mode === DeleteMode::Quarantine && $quarantinedTo !== null && preg_match(config('devices.delete_path.quarantine_folder_pattern'), (string) ($result['quarantine_folder'] ?? '')) === 1) {
            $this->recordQuarantine($command, $result, $path, $quarantinedTo, $bytes, $files);
        }

        ($this->rescanDrive)($command, $path);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordQuarantine(DeviceCommand $command, array $result, string $path, string $quarantinedTo, int $bytes, int $files): void
    {
        $quarantinedAt = $command->completed_at ?? now();

        DeviceQuarantine::query()->firstOrCreate(['device_command_id' => $command->id], [
            'device_id' => $command->device_id,
            'path' => $path,
            'kind' => PathKind::tryFrom((string) ($result['kind'] ?? '')) ?? PathKind::Any,
            'folder' => $result['quarantine_folder'],
            'quarantined_to' => $quarantinedTo,
            'bytes' => $bytes,
            'files' => $files,
            'quarantined_at' => $quarantinedAt,
            'purge_after' => $quarantinedAt->copy()->addDays(config('devices.delete_path.quarantine_days')),
        ]);
    }
}
