<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceListFilter;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;

final class DeviceListQueries
{
    public function __construct(private readonly AgentVersionQueries $agentVersions) {}

    /**
     * Everything a row shows, loaded up front so the list costs the same however many devices it holds.
     */
    public function rows(): Builder
    {
        return Device::query()->with(['latestMetric.diskMetrics', 'group', 'tags']);
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
