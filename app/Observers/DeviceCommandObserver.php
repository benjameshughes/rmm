<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DeviceCommand;

final class DeviceCommandObserver
{
    /**
     * A finished command never needs its secrets again, however it finished.
     */
    public function updated(DeviceCommand $command): void
    {
        if ($command->status->isTerminal()) {
            $command->secrets()->delete();
        }
    }
}
