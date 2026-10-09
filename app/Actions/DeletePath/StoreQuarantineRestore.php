<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\DiskUsage\RescanDrive;
use App\Enums\AuditAction;
use App\Models\DeviceCommand;

/**
 * Marks a quarantined item as restored once restore-quarantine moved it
 * back, audits it and rescans its drive.
 */
final class StoreQuarantineRestore
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly RescanDrive $rescanDrive,
    ) {}

    public function __invoke(DeviceCommand $command): void
    {
        $result = $command->resultJson();

        if (($result['schema'] ?? null) !== config('devices.delete_path.restore_schema') || ($result['restored'] ?? false) !== true) {
            return;
        }

        $quarantine = $command->device->quarantines()->held()->where('folder', (string) ($result['folder'] ?? ''))->first();

        if ($quarantine === null) {
            return;
        }

        $quarantine->update(['restored_at' => now()]);

        ($this->recordAuditEvent)(AuditAction::QuarantineRestored, $command->device, [
            'label' => "{$quarantine->path} on {$command->device->hostname}",
            'path' => $quarantine->path,
            'bytes' => $quarantine->bytes,
            'command_id' => $command->id,
        ], actor: $command->queuedBy);

        ($this->rescanDrive)($command, $quarantine->path);
    }
}
