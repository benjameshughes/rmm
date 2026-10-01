<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\DeviceGroup;

final class AssignDeviceGroup
{
    public function __invoke(Device $device, ?DeviceGroup $group): void
    {
        $device->update(['device_group_id' => $group?->id]);
    }
}
