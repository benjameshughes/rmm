<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\DeviceCommand;
use Flux\Flux;

trait CancelsCommands
{
    /**
     * The agent may fetch the command between page render and click, in which case
     * it is past cancelling: say so rather than refuse, and leave it alone.
     */
    public function cancelCommand(int $commandId): void
    {
        $command = DeviceCommand::query()->with(['device', 'script'])->findOrFail($commandId);

        if (! $command->isPending()) {
            $this->toastAlreadyStarted($command);

            return;
        }

        $this->authorize('cancel', $command);

        if (! $command->cancel()) {
            $this->toastAlreadyStarted($command);

            return;
        }

        Flux::toast(text: "{$command->displayName()} will not run on {$command->device->hostname}.", heading: 'Command cancelled', variant: 'success');
    }

    private function toastAlreadyStarted(DeviceCommand $command): void
    {
        Flux::toast(text: "The agent on {$command->device->hostname} has already picked it up.", heading: 'Command already started', variant: 'warning');
    }
}
