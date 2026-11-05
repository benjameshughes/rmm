<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceRegrouped;
use App\Events\DeviceUpdated;
use App\Models\Device;
use App\Queries\StaffAccountQueries;

final readonly class SyncDeviceTags
{
    public function __construct(
        private StaffAccountQueries $staffAccounts,
    ) {}

    /** @param array<int> $tagIds */
    public function __invoke(Device $device, array $tagIds): void
    {
        $previousStaffAccountIds = $this->staffAccounts->accountIdsTargeting($device)->all();
        $changes = $device->tags()->sync($tagIds);

        if (collect($changes)->flatten()->isEmpty()) {
            return;
        }

        DeviceUpdated::dispatch($device, true);
        DeviceRegrouped::dispatch($device, $previousStaffAccountIds);
    }
}
