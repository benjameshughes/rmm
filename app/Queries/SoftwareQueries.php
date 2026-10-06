<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceStatus;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SoftwareQueries
{
    /**
     * A device's packages, updates first.
     */
    public function forDevice(Device $device, string $search = ''): Builder
    {
        return $device->software()
            ->getQuery()
            ->when($search !== '', fn (Builder $query): Builder => $this->matching($query, $search))
            ->orderByDesc('is_update_available')
            ->orderBy('name');
    }

    /**
     * One row per package across approved devices, most devices behind first: the
     * "a different version on every PC" problem.
     */
    public function fleetPackages(string $search = ''): Builder
    {
        return $this->onFleet(DeviceSoftware::query())
            ->when($search !== '', fn (Builder $query): Builder => $this->matching($query, $search))
            ->select('device_software.package_id')
            ->selectRaw('MAX(device_software.name) as name')
            ->selectRaw('MAX(device_software.source) as source')
            ->selectRaw('MAX(device_software.latest_version) as latest_version')
            ->selectRaw('COUNT(*) as device_count')
            ->selectRaw('SUM(CASE WHEN device_software.is_update_available THEN 1 ELSE 0 END) as outdated_count')
            ->selectRaw('COUNT(DISTINCT device_software.installed_version) as version_count')
            ->groupBy('device_software.package_id')
            ->orderByDesc('outdated_count')
            ->orderByDesc('version_count')
            ->orderByDesc('device_count')
            ->orderBy('name');
    }

    /**
     * How many devices run each version, busiest version first, e.g. "131.0 ×7, 129.0 ×3".
     *
     * @param  array<int, string>  $packageIds
     * @return Collection<string, string>
     */
    public function versionSpread(array $packageIds): Collection
    {
        return $this->onFleet(DeviceSoftware::query())
            ->whereIn('device_software.package_id', $packageIds)
            ->select('device_software.package_id', 'device_software.installed_version')
            ->selectRaw('COUNT(*) as installs')
            ->groupBy('device_software.package_id', 'device_software.installed_version')
            ->toBase()
            ->get()
            ->groupBy('package_id')
            ->map(fn (Collection $versions): string => $versions
                ->sortByDesc('installs')
                ->map(fn (object $version): string => ($version->installed_version ?? 'unknown').' ×'.$version->installs)
                ->implode(', '));
    }

    /**
     * Every device with the package, outdated first, with the device loaded for its row.
     */
    public function installsOf(string $packageId): Builder
    {
        return $this->onFleet(DeviceSoftware::query())
            ->where('device_software.package_id', $packageId)
            ->select('device_software.*')
            ->with(['device.group', 'device.tags'])
            ->orderByDesc('device_software.is_update_available')
            ->orderBy('devices.hostname');
    }

    /**
     * Devices that take commands and have a software inventory, but no install of the package.
     *
     * @return Builder<Device>
     */
    public function devicesMissing(string $packageId): Builder
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->whereNotNull('software_inventoried_at')
            ->whereDoesntHave('software', fn (Builder $softwareQuery): Builder => $softwareQuery->where('package_id', $packageId))
            ->orderBy('hostname');
    }

    /**
     * The install, upgrade or uninstall still in flight for each of a device's packages.
     *
     * @return Collection<string, DeviceCommand> keyed by package ID
     */
    public function packageCommandsOnDevice(Device $device): Collection
    {
        return $this->inFlightPackageCommands()->where('device_id', $device->id)->get()
            ->keyBy(fn (DeviceCommand $command): string => (string) ($command->parameters['PackageId'] ?? ''));
    }

    /**
     * The install, upgrade or uninstall of one package still in flight on each device.
     *
     * @return Collection<int, DeviceCommand> keyed by device ID
     */
    public function packageCommandsFor(string $packageId): Collection
    {
        return $this->inFlightPackageCommands()->get()
            ->filter(fn (DeviceCommand $command): bool => ($command->parameters['PackageId'] ?? null) === $packageId)
            ->keyBy('device_id');
    }

    /** @return Builder<DeviceCommand> Oldest first, so keying keeps the newest */
    private function inFlightPackageCommands(): Builder
    {
        return DeviceCommand::query()
            ->with('script')
            ->inFlight()
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->whereIn('slug', collect(PackageAction::cases())->map->value))
            ->oldest('id');
    }

    private function onFleet(Builder $query): Builder
    {
        return $query
            ->join('devices', 'devices.id', '=', 'device_software.device_id')
            ->where('devices.status', DeviceStatus::Active)
            ->where(fn (Builder $flagQuery): Builder => $flagQuery->whereNull('devices.is_monitor_only')->orWhere('devices.is_monitor_only', false));
    }

    private function matching(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
            ->where('device_software.name', 'like', "%{$search}%")
            ->orWhere('device_software.package_id', 'like', "%{$search}%"));
    }
}
