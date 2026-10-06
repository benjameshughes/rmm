<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceListFilter;
use App\Enums\DeviceListSort;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceDiskMetric;
use App\Models\DeviceGroup;
use App\Models\DeviceMetric;
use Illuminate\Database\Eloquent\Builder;

final class DeviceListQueries
{
    public function __construct(private readonly AgentVersionQueries $agentVersions) {}

    /**
     * Everything a row shows, loaded up front so the list costs the same however many devices it holds.
     */
    public function rows(): Builder
    {
        return Device::query()->with(['latestMetric.diskMetrics', 'group', 'tags', 'inFlightCommands.script']);
    }

    /**
     * Orders by the chosen column with empty values last, then by hostname and id, so
     * equal rows never swap places between redraws.
     */
    public function sort(Builder $query, DeviceListSort $sort, string $direction): Builder
    {
        $sorted = $query->select('devices.*');

        $sorted = match ($sort) {
            DeviceListSort::Hostname => $sorted->selectRaw('devices.hostname as sort_value'),
            DeviceListSort::Status => $sorted->addStatusRank('sort_value'),
            DeviceListSort::Group => $sorted->selectSub(DeviceGroup::query()->select('name')->whereColumn('device_groups.id', 'devices.device_group_id'), 'sort_value'),
            DeviceListSort::Cpu => $sorted->selectSub($this->latestMetricColumn('cpu'), 'sort_value'),
            DeviceListSort::Ram => $sorted->selectSub($this->latestMetricColumn('ram'), 'sort_value'),
            DeviceListSort::Disk => $sorted->selectSub($this->fullestReportedDisk(), 'sort_value'),
            DeviceListSort::Agent => $sorted->selectRaw('devices.agent_version as sort_value'),
            DeviceListSort::LastSeen => $sorted->selectRaw('devices.last_seen as sort_value'),
        };

        return $sorted
            ->orderByRaw('CASE WHEN sort_value IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort_value', $direction)
            ->orderBy('devices.hostname')
            ->orderBy('devices.id');
    }

    private function latestMetric(): Builder
    {
        return DeviceMetric::query()
            ->whereColumn('device_metrics.device_id', 'devices.id')
            ->orderByDesc('device_metrics.recorded_at')
            ->orderByDesc('device_metrics.id')
            ->limit(1);
    }

    private function latestMetricColumn(string $column): Builder
    {
        return $this->latestMetric()->select("device_metrics.{$column}");
    }

    /**
     * The fullest volume in the latest report. Devices that only sent an enrolment disk list sort as empty.
     */
    private function fullestReportedDisk(): Builder
    {
        return DeviceDiskMetric::query()
            ->selectRaw('MAX((device_disk_metrics.total_gb - device_disk_metrics.available_gb) * 100.0 / device_disk_metrics.total_gb)')
            ->where('device_disk_metrics.total_gb', '>', 0)
            ->where('device_disk_metrics.device_metric_id', $this->latestMetric()->select('device_metrics.id'));
    }

    public function filter(Builder $query, DeviceListFilter $filter): Builder
    {
        return match ($filter) {
            DeviceListFilter::Online => $query->online(),
            DeviceListFilter::PoweringOff => $query->poweringOff(),
            DeviceListFilter::Offline => $query->offline(),
            DeviceListFilter::Outdated => $query->whereKey($this->agentVersions->outdatedDevices()->modelKeys()),
        };
    }

    /**
     * @return array{total: int, online: int, poweringOff: int, offline: int, outdated: int, openAlerts: int}
     */
    public function summary(): array
    {
        return [
            'total' => Device::query()->count(),
            'online' => Device::query()->online()->count(),
            'poweringOff' => Device::query()->poweringOff()->count(),
            'offline' => Device::query()->offline()->count(),
            'outdated' => $this->agentVersions->outdatedDevices()->count(),
            'openAlerts' => Alert::query()->unresolved()->count(),
        ];
    }
}
