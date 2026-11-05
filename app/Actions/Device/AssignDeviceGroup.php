<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceRegrouped;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Queries\StaffAccountQueries;

final readonly class AssignDeviceGroup
{
    public function __construct(
        private StaffAccountQueries $staffAccounts,
    ) {}

    public function __invoke(Device $device, ?DeviceGroup $group): void
    {
        if ($device->device_group_id === $group?->id) {
            return;
        }

        $previousStaffAccountIds = $this->staffAccounts->accountIdsTargeting($device)->all();
        $device->update(['device_group_id' => $group?->id]);

        DeviceRegrouped::dispatch($device, $previousStaffAccountIds);
    }
}
