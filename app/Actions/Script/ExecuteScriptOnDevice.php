<?php

declare(strict_types=1);

namespace App\Actions\Script;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\User;
use App\Queries\AgentVersionQueries;
use Illuminate\Validation\ValidationException;

final class ExecuteScriptOnDevice
{
    public function __construct(
        private AgentVersionQueries $agentVersions,
    ) {}

    /**
     * @param  ScheduledTask|null  $scheduledTask  The schedule that queued this run, so its result can raise or resolve an alert
     * @param  array<string, string>  $parameters  Values already checked by ValidateScriptParameterValues
     *
     * @throws ValidationException When the device is monitor only, or the script has parameters and the device's agent is too old to pass them on
     */
    public function __invoke(Script $script, Device $device, User $user, ?ScheduledTask $scheduledTask = null, array $parameters = []): DeviceCommand
    {
        throw_if(
            $device->isMonitorOnly,
            ValidationException::withMessages(['script' => "{$device->hostname} is monitor only and never runs commands."]),
        );

        throw_if(
            $script->parameters->isNotEmpty() && ! $this->agentVersions->supportsScriptParameters($device),
            ValidationException::withMessages(['script' => $this->outdatedAgentMessage($device)]),
        );

        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_id' => $script->id,
            'scheduled_task_id' => $scheduledTask?->id,
            'script_content' => $script->script_content,
            'script_type' => $script->script_type->value,
            'parameters' => $parameters === [] ? null : $parameters,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $script->timeout_seconds,
        ]);
    }

    private function outdatedAgentMessage(Device $device): string
    {
        $version = $device->agent_version === null ? 'an unknown agent version' : "agent {$device->agent_version}";
        $required = config('agent.parameters_min_version');

        return "{$device->hostname} runs {$version}. Scripts with parameters need agent {$required} or newer, so update the agent first.";
    }
}
