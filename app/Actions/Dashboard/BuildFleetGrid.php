<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\Dashboard\FleetGroup;
use App\DTOs\Dashboard\FleetSquare;
use App\DTOs\DeviceAttention;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The fleet grid: one square per device, grouped Windows then Linux, built from the already loaded fleet.
 */
final class BuildFleetGrid
{
    /**
     * @param  EloquentCollection<int, Device>  $fleet
     * @param  Collection<int, DeviceAttention>  $attention
     * @return Collection<int, FleetGroup>
     */
    public function __invoke(EloquentCollection $fleet, Collection $attention): Collection
    {
        $attentionIds = $attention->map(fn (DeviceAttention $item): int => $item->device->id)->flip();

        return collect([ScriptPlatform::Windows, ScriptPlatform::Linux])
            ->map(fn (ScriptPlatform $platform): FleetGroup => $this->group(
                name: $platform->name,
                devices: $fleet->filter(fn (Device $device): bool => $device->platform() === $platform),
                attentionIds: $attentionIds,
            ))
            ->filter(fn (FleetGroup $group): bool => $group->squares->isNotEmpty())
            ->values();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, int>  $attentionIds
     */
    private function group(string $name, Collection $devices, Collection $attentionIds): FleetGroup
    {
        return new FleetGroup(
            name: $name,
            squares: $devices->map(fn (Device $device): FleetSquare => new FleetSquare(
                device: $device,
                statusLabel: $device->statusLabel().($device->isMonitorOnly ? ' · monitor only' : ''),
                needsAttention: $attentionIds->has($device->id),
                cpu: $device->latestMetric?->cpuRoundedForHumans(),
                ram: $device->latestMetric?->ramRoundedForHumans(),
                worstDisk: $this->worstDisk($device),
                lastSeen: $device->lastSeenForHumans(),
            ))->values(),
            offline: $devices->filter(fn (Device $device): bool => ! $device->isOnline && ! $device->isPoweringOff)->count(),
        );
    }

    private function worstDisk(Device $device): ?string
    {
        $disk = $device->fullestDisk();

        return $disk === null ? null : "{$disk['name']} {$disk['usedForHumans']}";
    }
}
