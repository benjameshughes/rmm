<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\DiskUsage\RescanDrive;
use App\Enums\AuditAction;
use App\Models\DeviceCommand;
use App\Models\DeviceQuarantine;
use Illuminate\Support\Collection;

/**
 * Marks the quarantined items a finished purge-quarantine run removed as
 * purged, audits each, and rescans the drive the space came back on.
 */
final class StoreQuarantinePurge
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly RescanDrive $rescanDrive,
    ) {}

    public function __invoke(DeviceCommand $command): void
    {
        $result = $command->resultJson();

        if (($result['schema'] ?? null) !== config('devices.delete_path.purge_schema')) {
            return;
        }

        $folders = collect(is_array($result['purged'] ?? null) ? $result['purged'] : [])
            ->map(fn (mixed $purged): mixed => is_array($purged) ? ($purged['folder'] ?? null) : null)
            ->filter(fn (mixed $folder): bool => is_string($folder));

        $purged = $this->purge($command, $folders);

        $purged->each(fn (DeviceQuarantine $quarantine) => ($this->recordAuditEvent)(AuditAction::QuarantinePurged, $command->device, [
            'label' => "{$quarantine->path} on {$command->device->hostname}",
            'path' => $quarantine->path,
            'bytes' => $quarantine->bytes,
            'command_id' => $command->id,
        ], actor: $command->queuedBy));

        if ($purged->isNotEmpty()) {
            ($this->rescanDrive)($command, $purged->first()->quarantined_to);
        }
    }

    /**
     * @param  Collection<int, string>  $folders
     * @return Collection<int, DeviceQuarantine>
     */
    private function purge(DeviceCommand $command, Collection $folders): Collection
    {
        $quarantines = $folders->isEmpty() ? collect() : $command->device->quarantines()->held()->whereIn('folder', $folders)->get();

        $quarantines->each(fn (DeviceQuarantine $quarantine) => $quarantine->update(['purged_at' => now()]));

        return $quarantines;
    }
}
