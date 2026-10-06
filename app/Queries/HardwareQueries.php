<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceStatus;
use App\Enums\HardwareSort;
use App\Models\Device;
use App\Models\DeviceInventory;
use Illuminate\Database\Eloquent\Builder;

final class HardwareQueries
{
    /**
     * One row per approved device with a system inventory, its latest inventory loaded.
     * Ordered by the chosen column with empty values last, then by hostname and id.
     *
     * @return Builder<Device>
     */
    public function fleet(string $search, HardwareSort $sort, string $direction): Builder
    {
        $devices = $this->onFleet()
            ->has('latestInventory')
            ->when($search !== '', fn (Builder $query): Builder => $this->matching($query, $search))
            ->with(['latestInventory', 'group', 'tags'])
            ->select('devices.*');

        $sorted = match ($sort) {
            HardwareSort::Hostname => $devices->selectRaw('devices.hostname as sort_value'),
            HardwareSort::Model => $devices->selectSub($this->latestInventoryColumn('model'), 'sort_value'),
            HardwareSort::Ram => $devices->selectSub($this->latestInventoryColumn('total_ram_gb'), 'sort_value'),
            HardwareSort::Collected => $devices->selectSub($this->latestInventoryColumn('collected_at'), 'sort_value'),
        };

        return $sorted
            ->orderByRaw('CASE WHEN sort_value IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort_value', $direction)
            ->orderBy('devices.hostname')
            ->orderBy('devices.id');
    }

    /**
     * Approved Windows devices that could run the system inventory but have never sent one.
     */
    public function devicesWithoutInventoryCount(): int
    {
        return $this->onFleet()
            ->where(fn (Builder $platformQuery): Builder => $platformQuery
                ->where('devices.os', 'like', '%windows%')
                ->orWhere('devices.os_name', 'like', '%windows%')
                ->orWhere('devices.kernel_name', 'like', '%windows%'))
            ->doesntHave('inventories')
            ->count();
    }

    /** @return Builder<Device> */
    private function onFleet(): Builder
    {
        return Device::query()
            ->where('devices.status', DeviceStatus::Active)
            ->acceptsCommands();
    }

    private function latestInventoryColumn(string $column): Builder
    {
        return DeviceInventory::query()
            ->select("device_inventories.{$column}")
            ->whereColumn('device_inventories.device_id', 'devices.id')
            ->orderByDesc('device_inventories.collected_at')
            ->orderByDesc('device_inventories.id')
            ->limit(1);
    }

    /**
     * Promoted columns match with a plain LIKE. The CPU and monitor list live in the JSON,
     * which MariaDB compares case-sensitively, so both sides are lowercased there.
     */
    private function matching(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';

        return $query->where(fn (Builder $searchQuery): Builder => $searchQuery
            ->where('devices.hostname', 'like', "%{$search}%")
            ->orWhereHas('latestInventory', fn (Builder $inventoryQuery): Builder => $inventoryQuery->where(fn (Builder $columnsQuery): Builder => $columnsQuery
                ->where('device_inventories.manufacturer', 'like', "%{$search}%")
                ->orWhere('device_inventories.model', 'like', "%{$search}%")
                ->orWhere('device_inventories.serial_number', 'like', "%{$search}%")
                ->orWhere('device_inventories.windows_edition', 'like', "%{$search}%")
                ->orWhereRaw($this->lowercased($inventoryQuery, 'device_inventories.data->cpu->name').' like ?', [$term])
                ->orWhereRaw($this->lowercased($inventoryQuery, 'device_inventories.data->monitors').' like ?', [$term]))));
    }

    private function lowercased(Builder $query, string $column): string
    {
        return 'lower('.$query->getQuery()->getGrammar()->wrap($column).')';
    }
}
