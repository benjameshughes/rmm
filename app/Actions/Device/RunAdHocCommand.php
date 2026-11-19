<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Enums\CommandRunAs;
use App\Enums\CommandStatus;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Queries\AgentVersionQueries;
use Illuminate\Validation\ValidationException;

final class RunAdHocCommand
{
    public function __construct(
        private readonly AgentVersionQueries $agentVersions,
    ) {}

    /**
     * Queue a typed command with no script behind it. The audit log records its
     * full text on creation, since there is no script to point back to.
     *
     * @throws ValidationException When the device is monitor only, or the command runs as the logged-in user and the device cannot
     */
    public function __invoke(Device $device, User $user, string $commandText, ScriptType $type, int $timeoutSeconds, CommandRunAs $runAs = CommandRunAs::System): DeviceCommand
    {
        throw_if(
            $device->isMonitorOnly,
            ValidationException::withMessages(['commandText' => "{$device->hostname} is monitor only and never runs commands."]),
        );

        throw_if(
            ! $runAs->isSystem() && $device->platform() !== ScriptPlatform::Windows,
            ValidationException::withMessages(['commandRunAs' => "Only Windows devices can run a command as the logged-in user, so {$device->hostname} runs it as System."]),
        );

        throw_if(
            ! $runAs->isSystem() && ! $this->agentVersions->supportsRunAs($device),
            ValidationException::withMessages(['commandRunAs' => $this->outdatedAgentMessage($device)]),
        );

        return DeviceCommand::create([
            'device_id' => $device->id,
            'script_id' => null,
            'script_content' => $commandText,
            'script_type' => $type->value,
            'run_as' => $runAs,
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => $timeoutSeconds,
        ]);
    }

    private function outdatedAgentMessage(Device $device): string
    {
        $version = $device->agent_version === null ? 'an unknown agent version' : "agent {$device->agent_version}";
        $required = config('agent.run_as_min_version');

        return "{$device->hostname} runs {$version}. Running as the logged-in user needs agent {$required} or newer, so update the agent first.";
    }
}
