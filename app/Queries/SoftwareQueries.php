<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Software\SoftwareFilters;
use App\Enums\DeviceStatus;
use App\Enums\PackageAction;
use App\Enums\SoftwareCoverage;
use App\Enums\SoftwareSort;
use App\Enums\SoftwareSource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class SoftwareQueries
{
    /**
     * A device's packages, updates first. Group, tag and coverage filters mean nothing on one device.
     */
    public function forDevice(Device $device, SoftwareFilters $filters = new SoftwareFilters): Builder
    {
        return $this->withoutRuntimes($device->software()->getQuery())
            ->tap(fn (Builder $query): Builder => $this->filtered($query, $filters))
            ->when($filters->isOutdatedOnly, fn (Builder $query): Builder => $query->where('device_software.is_update_available', true))
            ->orderByDesc('is_update_available')
            ->orderBy('name');
    }

    /**
     * One row per package across approved devices, by default most devices behind
     * first: the "a different version on every PC" problem. A group or tag filter
     * counts only the installs on its devices.
     */
    public function fleetPackages(SoftwareFilters $filters = new SoftwareFilters, SoftwareSort $sort = SoftwareSort::Behind, string $direction = 'desc'): Builder
    {
        return $this->withoutRuntimes($this->onFleet(DeviceSoftware::query(), $filters))
            ->tap(fn (Builder $query): Builder => $this->filtered($query, $filters))
            ->select('device_software.package_id')
            ->selectRaw('MAX(device_software.name) as name')
            ->selectRaw('MAX(device_software.source) as source')
            ->selectRaw('MAX(device_software.latest_version) as latest_version')
            ->selectRaw('COUNT(*) as device_count')
            ->selectRaw('SUM(CASE WHEN device_software.is_update_available THEN 1 ELSE 0 END) as outdated_count')
            ->selectRaw('COUNT(DISTINCT device_software.installed_version) as version_count')
            ->groupBy('device_software.package_id')
            ->when($filters->isOutdatedOnly, fn (Builder $query): Builder => $query->havingRaw('SUM(CASE WHEN device_software.is_update_available THEN 1 ELSE 0 END) > 0'))
            ->when($filters->coverage !== null, fn (Builder $query): Builder => $query->havingRaw(
                'COUNT(DISTINCT device_software.device_id) '.($filters->coverage === SoftwareCoverage::Every ? '>=' : '<').' ?',
                [$this->inventoriedDeviceCount($filters)],
            ))
            ->orderBy($sort->column(), $direction)
            ->when($sort === SoftwareSort::Behind, fn (Builder $query): Builder => $query->orderByDesc('version_count')->orderByDesc('device_count'))
            ->orderBy('name');
    }

    /**
     * Approved Windows devices with a software inventory, within the group or tag filter.
     */
    public function inventoriedDeviceCount(SoftwareFilters $filters = new SoftwareFilters): int
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->whereNotNull('software_inventoried_at')
            ->when($filters->groupId !== null, fn (Builder $query): Builder => $query->where('device_group_id', $filters->groupId))
            ->when($filters->tagId !== null, fn (Builder $query): Builder => $query->whereRelation('tags', 'tags.id', $filters->tagId))
            ->count();
    }

    /**
     * The installs of the chosen packages a bulk action reaches, within the group or tag filter.
     *
     * @param  array<int, string>  $packageIds
     * @return Builder<DeviceSoftware>
     */
    public function installsOfPackages(array $packageIds, SoftwareFilters $filters = new SoftwareFilters): Builder
    {
        return $this->onFleet(DeviceSoftware::query(), $filters)
            ->whereIn('device_software.package_id', $packageIds)
            ->select('device_software.*')
            ->with('device');
    }

    /**
     * winget-source package IDs already seen across the fleet, for picking one to install.
     *
     * @return Collection<int, string>
     */
    public function knownWingetPackageIds(string $search = ''): Collection
    {
        return DeviceSoftware::query()
            ->where('source', config('software.upgradable_source'))
            ->when($search !== '', fn (Builder $query): Builder => $this->matching($query, $search))
            ->distinct()
            ->orderBy('package_id')
            ->limit(config('software.install_suggestions'))
            ->pluck('package_id');
    }

    /**
     * How many devices run each version, busiest version first, e.g. "131.0 ×7, 129.0 ×3".
     *
     * @param  array<int, string>  $packageIds
     * @return Collection<string, string>
     */
    public function versionSpread(array $packageIds, SoftwareFilters $filters = new SoftwareFilters): Collection
    {
        return $this->onFleet(DeviceSoftware::query(), $filters)
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

    /**
     * Each package's in-flight installs, upgrades and uninstalls, running ones first.
     *
     * @param  array<int, string>  $packageIds
     * @return Collection<string, Collection<int, DeviceCommand>> keyed by package ID
     */
    public function packageCommandsByPackage(array $packageIds): Collection
    {
        return $this->inFlightPackageCommands()->get()
            ->filter(fn (DeviceCommand $command): bool => in_array($command->parameters['PackageId'] ?? null, $packageIds, true))
            ->sortBy(fn (DeviceCommand $command): int => $command->isPending() ? 1 : 0)
            ->groupBy(fn (DeviceCommand $command): string => (string) $command->parameters['PackageId']);
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

    private function onFleet(Builder $query, SoftwareFilters $filters = new SoftwareFilters): Builder
    {
        return $query
            ->join('devices', 'devices.id', '=', 'device_software.device_id')
            ->where('devices.status', DeviceStatus::Active)
            ->where(fn (Builder $flagQuery): Builder => $flagQuery->whereNull('devices.is_monitor_only')->orWhere('devices.is_monitor_only', false))
            ->when($filters->groupId !== null, fn (Builder $scopeQuery): Builder => $scopeQuery->where('devices.device_group_id', $filters->groupId))
            ->when($filters->tagId !== null, fn (Builder $scopeQuery): Builder => $scopeQuery->whereRelation('device.tags', 'tags.id', $filters->tagId));
    }

    private function filtered(Builder $query, SoftwareFilters $filters): Builder
    {
        return $query
            ->when($filters->search !== '', fn (Builder $searchQuery): Builder => $this->matching($searchQuery, $filters->search))
            ->when($filters->source !== null, fn (Builder $sourceQuery): Builder => $this->fromSource($sourceQuery, $filters->source));
    }

    private function fromSource(Builder $query, SoftwareSource $source): Builder
    {
        return $source === SoftwareSource::Winget
            ? $query->where('device_software.source', config('software.upgradable_source'))
            : $query->whereNull('device_software.source')->where('device_software.package_id', 'like', $source->packageIdPattern());
    }

    /**
     * Leaves out the Microsoft runtimes other apps depend on (config software.hidden).
     */
    private function withoutRuntimes(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $hidden): Builder => $hidden
            ->where(fn (Builder $ids): Builder => collect(config('software.hidden.package_ids'))
                ->reduce(fn (Builder $carry, string $pattern): Builder => $carry->orWhere('device_software.package_id', 'like', $pattern), $ids))
            ->orWhere(fn (Builder $names): Builder => collect(config('software.hidden.names'))
                ->reduce(fn (Builder $carry, string $pattern): Builder => $carry->orWhere('device_software.name', 'like', $pattern), $names)));
    }

    private function matching(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
            ->where('device_software.name', 'like', "%{$search}%")
            ->orWhere('device_software.package_id', 'like', "%{$search}%"));
    }
}
