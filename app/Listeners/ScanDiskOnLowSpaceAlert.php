<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DiskUsage\QueueDiskScan;
use App\Enums\AlertMetric;
use App\Enums\ScriptPlatform;
use App\Events\AlertChanged;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceDiskMetric;
use App\Models\User;
use App\Queries\AgentVersionQueries;
use Illuminate\Database\Eloquent\Builder;

/**
 * When a disk usage alert opens on a Windows PC, scans its fullest drive so
 * the Storage tab already shows what filled it. Skipped when that drive was
 * scanned, by anyone, within the cooldown.
 */
final class ScanDiskOnLowSpaceAlert
{
    public function __construct(
        private readonly QueueDiskScan $queueDiskScan,
        private readonly AgentVersionQueries $agentVersions,
    ) {}

    public function handle(AlertChanged $event): void
    {
        if (! $event->isNewlyTriggered()) {
            return;
        }

        $alert = Alert::query()->with('device.latestMetric.diskMetrics')->find($event->alertId);
        $device = $alert?->device;

        if ($alert?->metric !== AlertMetric::Disk || ! $this->canScan($device)) {
            return;
        }

        $drive = $this->fullestDrive($device);

        if ($drive === null || $this->wasScannedRecently($device, $drive)) {
            return;
        }

        ($this->queueDiskScan)($device, User::automation(), $drive);
    }

    private function canScan(?Device $device): bool
    {
        return $device !== null
            && $device->platform() === ScriptPlatform::Windows
            && ! $device->isMonitorOnly
            && $this->agentVersions->supportsScriptParameters($device);
    }

    /**
     * The drive root of the fullest volume, which is the one that raised the
     * alert. Netdata names Windows volumes by drive letter ("C:").
     */
    private function fullestDrive(Device $device): ?string
    {
        $mountPoint = $device->latestMetric?->diskMetrics
            ->sortByDesc(fn (DeviceDiskMetric $volume): float => (float) $volume->usage_percent)
            ->first()
            ?->mount_point;

        return preg_match('/^([A-Za-z]):/', (string) $mountPoint, $matches) === 1 ? strtoupper($matches[1]).':\\' : null;
    }

    private function wasScannedRecently(Device $device, string $drive): bool
    {
        return $device->commands()
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->where('slug', config('disk_usage.slug')))
            ->where('parameters->Path', $drive)
            ->where('queued_at', '>=', now()->subHours(config('disk_usage.auto_scan_cooldown_hours')))
            ->exists();
    }
}
