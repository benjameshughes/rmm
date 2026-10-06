<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\SyncDeviceTags;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use Flux\Flux;
use Illuminate\Support\Collection;

/**
 * Bulk group and tag changes for the devices ticked on the list. Organising is
 * not managing, so monitor-only devices are included.
 */
trait OrganisesSelectedDevices
{
    public function bulkAssignGroup(?int $groupId, AssignDeviceGroup $action): void
    {
        $group = $groupId === null ? null : DeviceGroup::query()->findOrFail($groupId);
        $devices = $this->organisableSelectedDevices();

        $devices->each(fn (Device $device) => $action($device, $group));

        Flux::toast(text: $group === null ? 'Removed from their group.' : "Moved to {$group->name}.", heading: $devices->count().' '.str('device')->plural($devices->count()).' updated', variant: 'success');
    }

    public function bulkAddTag(int $tagId, SyncDeviceTags $action): void
    {
        $tag = Tag::query()->findOrFail($tagId);
        $devices = $this->organisableSelectedDevices();

        $devices->each(fn (Device $device) => $action($device, $device->tags->pluck('id')->push($tag->id)->unique()->all()));

        Flux::toast(text: "Tagged {$tag->name}.", heading: $devices->count().' '.str('device')->plural($devices->count()).' updated', variant: 'success');
    }

    public function bulkRemoveTag(int $tagId, SyncDeviceTags $action): void
    {
        $tag = Tag::query()->findOrFail($tagId);
        $devices = $this->organisableSelectedDevices();

        $devices->each(fn (Device $device) => $action($device, $device->tags->pluck('id')->reject(fn (int $id): bool => $id === $tag->id)->all()));

        Flux::toast(text: "Removed the {$tag->name} tag.", heading: $devices->count().' '.str('device')->plural($devices->count()).' updated', variant: 'success');
    }

    /** @return Collection<int, Device> */
    private function organisableSelectedDevices(): Collection
    {
        return Device::query()->with('tags')->whereIn('id', $this->selectedDevices)->get()
            ->each(fn (Device $device) => $this->authorize('manageGroupsAndTags', $device));
    }
}
