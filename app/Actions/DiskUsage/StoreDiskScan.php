<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\DTOs\DiskUsage\DiskScan;
use App\Events\DiskScanStored;
use App\Models\Device;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stores a disk-usage scan (a Windows PC's command output, or the scan a
 * Linux agent sends with its metrics) and keeps only the newest scans_kept
 * for that device and folder. Data that is not a scan this app reads,
 * including an unknown schema version, stores nothing.
 */
final class StoreDiskScan
{
    /**
     * @param  array<string, mixed>|null  $data  The decoded rmm.du/1 report
     * @param  array<string, mixed>  $logContext  Where the data came from, logged if it is unreadable
     * @return bool Whether a scan was stored
     */
    public function __invoke(Device $device, ?array $data, ?int $depth, CarbonInterface $scannedAt, array $logContext = []): bool
    {
        $scan = $data === null ? null : DiskScan::fromArray($data);

        if ($scan === null) {
            Log::warning('disk_usage.unreadable_scan', [
                'device_id' => $device->id,
                ...$logContext,
                'schema' => is_string($data['schema'] ?? null) ? Str::limit($data['schema'], 50) : null,
            ]);

            return false;
        }

        DB::transaction(function () use ($device, $scan, $data, $depth, $scannedAt): void {
            $device->diskScans()->create([
                'root' => $scan->root,
                'depth' => $depth,
                'scanned_at' => $scannedAt,
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
