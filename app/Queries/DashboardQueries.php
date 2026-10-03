<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\DeviceAttention;
use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The dashboard reads the approved fleet once, with each device's latest report and its disks,
 * and works every device section out from that one load.
 */
final class DashboardQueries
{
    public function __construct(private readonly DeviceListQueries $deviceList) {}

    /** @return EloquentCollection<int, Device> */
    public function fleet(): EloquentCollection
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->with('latestMetric.diskMetrics')
            ->orderBy('hostname')
            ->get();
    }

    /**
     * @return array{total: int, online: int, poweringOff: int, offline: int, outdated: int, openAlerts: int, managed: int, monitorOnly: int}
     */
    public function summary(): array
    {
        return [
            ...$this->deviceList->summary(),
            'managed' => Device::query()->where('status', DeviceStatus::Active)->acceptsCommands()->count(),
            'monitorOnly' => Device::query()->where('status', DeviceStatus::Active)->where('is_monitor_only', true)->count(),
        ];
    }

    /**
     * Devices with a disk or inodes at or over the warning threshold, an open alert, a failed
     * service, a silence they never announced, an old agent or a pending reboot, worst first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, DeviceAttention>
     */
    public function needsAttention(EloquentCollection $fleet, ?string $latestAgentVersion): Collection
    {
        $openAlerts = Alert::query()
            ->unresolved()
            ->whereIn('device_id', $fleet->modelKeys())
            ->with('alertRule')
            ->latest('triggered_at')
            ->get()
            ->groupBy('device_id');

        return $fleet
            ->map(fn (Device $device): DeviceAttention => $this->attentionFor($device, $openAlerts->get($device->id, collect()), $latestAgentVersion))
            ->filter(fn (DeviceAttention $attention): bool => $attention->needsAttention())
            ->sort(fn (DeviceAttention $first, DeviceAttention $second): int => $first->sortKey() <=> $second->sortKey())
            ->values();
    }

    /**
     * @param  Collection<int, Alert>  $openAlerts
     */
    private function attentionFor(Device $device, Collection $openAlerts, ?string $latestAgentVersion): DeviceAttention
    {
        $disks = $device->diskUsage();

        return new DeviceAttention(
            device: $device,
            disks: $disks
                ->filter(fn (array $disk): bool => $disk['usedPercent'] !== null && $disk['usedPercent'] >= config('devices.disk.warning_percent'))
                ->sortByDesc('usedPercent')
                ->values(),
            alerts: $openAlerts,
            isOfflineUnexpectedly: ! $device->isOnline && ! $device->isPoweringOff,
            isAgentBehind: $device->isAgentOutdated($latestAgentVersion),
            failedUnits: collect($device->latestMetric?->failed_units ?? []),
            inodeDisks: $disks
                ->filter(fn (array $disk): bool => $disk['inodePercent'] !== null && $disk['inodePercent'] >= config('devices.disk.inode_warning_percent'))
                ->sortByDesc('inodePercent')
                ->values(),
            isRebootRequired: (bool) $device->latestMetric?->reboot_required,
        );
    }

    /**
     * Each device's fullest disk, fullest first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, array{device: Device, disk: array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}}>
     */
    public function fullestDisks(EloquentCollection $fleet, int $limit): Collection
    {
        return $fleet
            ->map(fn (Device $device): array => ['device' => $device, 'disk' => $device->fullestDisk()])
            ->filter(fn (array $row): bool => ($row['disk']['usedPercent'] ?? null) !== null)
            ->sortByDesc(fn (array $row): float => $row['disk']['usedPercent'])
            ->take($limit)
            ->values();
    }

    /**
     * Online devices working hardest right now, by whichever of CPU and RAM is higher.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, Device>
     */
    public function busiest(EloquentCollection $fleet, int $limit): Collection
    {
        return $fleet
            ->filter(fn (Device $device): bool => $device->isOnline && $device->latestMetric !== null)
            ->sortByDesc(fn (Device $device): float => max($device->latestMetric->cpu ?? 0, $device->latestMetric->ram ?? 0))
            ->take($limit)
            ->values();
    }

    /** @return EloquentCollection<int, Alert> */
    public function recentAlerts(int $limit): EloquentCollection
    {
        return Alert::query()->with(['device', 'alertRule'])->latest('triggered_at')->latest('id')->limit($limit)->get();
    }

    /** @return EloquentCollection<int, AuditLog> */
    public function recentActivity(int $limit): EloquentCollection
    {
        return AuditLog::query()->with('user')->latest('id')->limit($limit)->get();
    }
}
