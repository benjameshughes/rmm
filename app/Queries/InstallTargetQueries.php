<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DeviceStatus;
use App\Enums\InstallTarget;
use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InstallTargetQueries
{
    /**
     * Approved Windows devices that take commands, in the chosen target, with
     * their install of the package (if their inventory lists one) loaded so the
     * ones that already have it can be skipped. Offline devices are included:
     * their command waits until they check in. A group or tag target with none
     * chosen yet matches nothing.
     *
     * @param  array<int, int>  $deviceIds  The ticked devices, for InstallTarget::Selected
     * @return Builder<Device>
     */
    public function devices(InstallTarget $target, string $packageId, array $deviceIds = [], ?int $groupId = null, ?int $tagId = null): Builder
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->acceptsCommands()
            ->runsWindows()
            ->tap(fn (Builder $query): Builder => match ($target) {
                InstallTarget::Selected => $query->whereIn('id', $deviceIds),
                InstallTarget::Group => $query->where('device_group_id', $groupId ?? 0),
                InstallTarget::Tag => $query->whereRelation('tags', 'tags.id', $tagId ?? 0),
                InstallTarget::All => $query,
            })
            ->with(['software' => fn (HasMany $softwareQuery): HasMany => $softwareQuery->where('package_id', $packageId)])
            ->orderBy('hostname');
    }
}
