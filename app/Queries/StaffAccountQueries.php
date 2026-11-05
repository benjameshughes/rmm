<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\StaffAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StaffAccountQueries
{
    /**
     * The approved Windows PCs that take commands and that the account targets
     * directly, through a group or through a tag, each once. Offline PCs are
     * included: their command waits until they check in.
     *
     * @return Builder<Device>
     */
    public function devices(StaffAccount $account): Builder
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->runsWindows()
            ->where(fn (Builder $targetQuery): Builder => $targetQuery
                ->whereIn('id', DB::table('staff_account_device')->where('staff_account_id', $account->id)->select('device_id'))
                ->orWhereIn('device_group_id', DB::table('staff_account_device_group')->where('staff_account_id', $account->id)->select('device_group_id'))
                ->orWhereIn('id', DB::table('device_tag')
                    ->join('staff_account_tag', 'staff_account_tag.tag_id', '=', 'device_tag.tag_id')
                    ->where('staff_account_tag.staff_account_id', $account->id)
                    ->select('device_tag.device_id')))
            ->orderBy('hostname');
    }

    /**
     * The ids of the staff accounts that target the PC directly, through its
     * group or through one of its tags, whatever its platform.
     *
     * @return Collection<int, int>
     */
    public function accountIdsTargeting(Device $device): Collection
    {
        return StaffAccount::query()
            ->whereIn('id', DB::table('staff_account_device')->where('device_id', $device->id)->select('staff_account_id'))
            ->orWhereIn('id', DB::table('staff_account_device_group')->where('device_group_id', $device->device_group_id ?? 0)->select('staff_account_id'))
            ->orWhereIn('id', DB::table('staff_account_tag')
                ->join('device_tag', 'device_tag.tag_id', '=', 'staff_account_tag.tag_id')
                ->where('device_tag.device_id', $device->id)
                ->select('staff_account_tag.staff_account_id'))
            ->pluck('id');
    }

    /**
     * The newest sync-local-user run per device for each account, newest first.
     *
     * @param  Collection<int, int>  $accountIds
     * @return Collection<int, Collection<int, DeviceCommand>> Keyed by account id, then device id
     */
    public function latestSyncs(Collection $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DeviceCommand::query()
            ->where('script_id', Script::findSystem('sync-local-user')->id)
            ->whereIn('parameters->StaffAccountId', $accountIds->map(fn (int $id): string => (string) $id))
            ->with('device:id,hostname')
            ->latest('id')
            ->get()
            ->groupBy(fn (DeviceCommand $command): int => (int) $command->parameters['StaffAccountId'])
            ->map(fn (Collection $commands): Collection => $commands->unique('device_id')->keyBy('device_id'));
    }
}
