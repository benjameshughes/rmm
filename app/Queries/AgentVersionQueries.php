<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

final class AgentVersionQueries
{
    public function latest(): ?string
    {
        return Cache::get(config('agent.latest_version_cache_key'));
    }

    /**
     * Agents older than the configured version ignore the parameters a
     * command carries, so parameterised scripts must not be sent to them.
     */
    public function supportsScriptParameters(Device $device): bool
    {
        return $device->agent_version !== null
            && version_compare($device->agent_version, config('agent.parameters_min_version'), '>=');
    }

    /**
     * Versions are compared in PHP with version_compare because SQL sorts them
     * as strings (0.10.0 < 0.9.0). Fine for a fleet of a few dozen devices.
     *
     * @return Collection<int, Device>
     */
    public function outdatedDevices(): Collection
    {
        $latestVersion = $this->latest();

        if ($latestVersion === null) {
            return new Collection;
        }

        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->whereNotNull('agent_version')
            ->get()
            ->filter(fn (Device $device): bool => $device->isAgentOutdated($latestVersion))
            ->values();
    }
}
