<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use App\Queries\AgentVersionQueries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class BulkExecuteScript
{
    public function __construct(
        private ExecuteScriptOnDevice $executeScript,
        private AgentVersionQueries $agentVersions,
    ) {}

    /**
     * Inactive devices are skipped, and so are devices whose agent is too old
     * for a parameterised script, so one old agent never stops the batch.
     *
     * @param  Collection<int, Device>  $devices
     * @param  array<string, string>  $parameters  Values already checked by ValidateScriptParameterValues
     * @param  ScheduledTask|null  $scheduledTask  The schedule running this batch, if any
     * @return int How many devices the script was queued on
     */
    public function __invoke(Script $script, Collection $devices, User $user, array $parameters = [], ?ScheduledTask $scheduledTask = null): int
    {
        [$eligible, $outdated] = $devices
            ->filter(fn (Device $device): bool => $device->status === DeviceStatus::Active)
            ->partition(fn (Device $device): bool => $script->parameters->isEmpty() || $this->agentVersions->supportsScriptParameters($device));

        $outdated->each(fn (Device $device) => Log::warning('script.skipped_outdated_agent', [
            'script_id' => $script->id,
            'device_id' => $device->id,
            'agent_version' => $device->agent_version,
        ]));

        return $eligible
            ->each(fn (Device $device) => ($this->executeScript)($script, $device, $user, $scheduledTask, $parameters))
            ->count();
    }
}
