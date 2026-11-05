<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Staff\RemoveStaffAccountFromDevices;
use App\Actions\Staff\SyncStaffAccount;
use App\Enums\ScriptPlatform;
use App\Events\DeviceRegrouped;
use App\Models\StaffAccount;
use App\Models\User;
use App\Queries\StaffAccountQueries;
use Illuminate\Contracts\Auth\Guard;

/**
 * Keeps a regrouped Windows PC's local users in step: every staff account
 * that targets it now is synced there, and every account that only targeted
 * it before is removed from it.
 */
final readonly class SyncStaffAccountsForRegroupedDevice
{
    public function __construct(
        private SyncStaffAccount $syncStaffAccount,
        private RemoveStaffAccountFromDevices $removeFromDevices,
        private StaffAccountQueries $staffAccounts,
        private Guard $guard,
    ) {}

    public function handle(DeviceRegrouped $event): void
    {
        if ($event->device->platform() !== ScriptPlatform::Windows) {
            return;
        }

        $current = $this->staffAccounts->accountIdsTargeting($event->device);
        $dropped = collect($event->previousStaffAccountIds)->diff($current);
        $devices = collect([$event->device]);
        $user = $this->guard->user() ?? User::automation();

        StaffAccount::query()->whereIn('id', $dropped)->get()
            ->each(fn (StaffAccount $account): int => ($this->removeFromDevices)($account, $devices, $user));

        StaffAccount::query()->whereIn('id', $current)->get()
            ->each(fn (StaffAccount $account): int => ($this->syncStaffAccount)($account, $user, $devices));
    }
}
