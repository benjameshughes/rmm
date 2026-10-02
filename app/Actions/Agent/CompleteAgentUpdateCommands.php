<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;

final class CompleteAgentUpdateCommands
{
    /**
     * The update stops the agent that is running it, so the old agent never
     * posts a result and the new one does not know about the command. The new
     * version arriving is the proof it worked, so that finishes the command.
     */
    public function __invoke(Device $device, ?string $previousVersion): void
    {
        $device->commands()
            ->whereIn('status', [CommandStatus::Sent, CommandStatus::Running])
            ->whereHas('script', fn ($query) => $query->where('slug', config('agent.update_script_slug')))
            ->get()
            ->each(fn (DeviceCommand $command) => $command->markAsCompleted(
                'Agent updated from '.($previousVersion ?? 'an unknown version')." to {$device->agent_version}.",
                0,
            ));
    }
}
