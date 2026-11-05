<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Events\DeviceRegrouped;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Queries\StaffAccountQueries;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a group empties it, so every PC in it is regrouped: staff accounts
 * that reached a PC only through the group are removed from it.
 */
final readonly class DeleteDeviceGroup
{
    public function __construct(
        private StaffAccountQueries $staffAccounts,
    ) {}

    public function __invoke(DeviceGroup $group): void
    {
        $previous = DB::transaction(function () use ($group): array {
            $previous = $group->devices()->get()
                ->mapWithKeys(fn (Device $device): array => [$device->id => $this->staffAccounts->accountIdsTargeting($device)->all()])
                ->all();

            $group->delete();

            return $previous;
        });

        Device::query()->whereKey(array_keys($previous))->get()
            ->each(fn (Device $device) => DeviceRegrouped::dispatch($device, $previous[$device->id]));
    }
}
