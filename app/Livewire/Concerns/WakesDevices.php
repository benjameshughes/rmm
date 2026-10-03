<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Device\WakeDevice;
use App\Models\Device;
use Flux\Flux;

trait WakesDevices
{
    private function wakeDevice(Device $device, WakeDevice $action): void
    {
        $this->authorize('wake', $device);

        $action($device);

        Flux::toast(text: 'It shows Online once the agent checks in, usually within a minute or two.', heading: "Wake packet sent to {$device->hostname}", variant: 'success');
    }
}
