<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;

final class SyncDeviceTags
{
    /** @param array<int> $tagIds */
    public function __invoke(Device $device, array $tagIds): void
    {
        $device->tags()->sync($tagIds);
    }
}
