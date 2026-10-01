<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceUpdated;
use App\Models\Device;

final class SyncDeviceTags
{
    /** @param array<int> $tagIds */
    public function __invoke(Device $device, array $tagIds): void
    {
        $changes = $device->tags()->sync($tagIds);

        if (collect($changes)->flatten()->isEmpty()) {
            return;
        }

        DeviceUpdated::dispatch($device);
    }
}
