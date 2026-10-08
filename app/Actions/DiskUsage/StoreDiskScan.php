<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\DTOs\DiskUsage\DiskScan;
use App\Events\DiskScanStored;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stores the scan a disk-usage run printed and keeps only the newest
 * scans_kept for that device and folder. Output that is not a scan this app
 * reads, including an unknown schema version, stores nothing.
 */
final class StoreDiskScan
{
    /**
     * @return bool Whether a scan was stored
     */
    public function __invoke(Device $device, DeviceCommand $command): bool
    {
        if ($device->isMonitorOnly) {
            return false;
        }

        $data = $command->resultJson();
        $scan = $data === null ? null : DiskScan::fromArray($data);

        if ($scan === null) {
            Log::warning('disk_usage.unreadable_scan', [
                'device_id' => $device->id,
                'command_id' => $command->id,
                'schema' => is_string($data['schema'] ?? null) ? Str::limit($data['schema'], 50) : null,
                'output_start' => Str::limit((string) $command->output, 200),
            ]);

            return false;
        }

        DB::transaction(function () use ($device, $command, $scan, $data): void {
            $device->diskScans()->create([
                'root' => $scan->root,
                'depth' => $this->depth($command),
                'scanned_at' => $command->completed_at ?? now(),
                'duration_ms' => $scan->durationMs,
                'allocated' => $scan->allocated,
                'files' => $scan->files,
                'error_count' => $scan->errorCount,
                'data' => $data,
            ]);

            $this->prune($device, $scan->root);
        });

        DiskScanStored::dispatch($device->id);

        return true;
    }

    private function depth(DeviceCommand $command): ?int
    {
        $depth = $command->parameters['Depth'] ?? config('disk_usage.default_depth');

        return is_numeric($depth) ? (int) $depth : null;
    }

    private function prune(Device $device, string $root): void
    {
        $keptIds = $device->diskScans()->ofRoot($root)
            ->latest('scanned_at')
            ->latest('id')
            ->limit(config('disk_usage.scans_kept'))
            ->pluck('id');

        $device->diskScans()->ofRoot($root)->whereNotIn('id', $keptIds)->delete();
    }
}
