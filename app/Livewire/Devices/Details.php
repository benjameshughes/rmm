<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\SyncDeviceTags;
use App\Enums\DeviceTab;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Details extends Component
{
    public Device $device;

    public ?string $selectedGroupId = null;

    public array $selectedTagIds = [];

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load(['latestMetric.networkMetrics', 'group', 'tags']);
        $this->syncSelectionsFromDevice();
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load('latestMetric.networkMetrics');
        $this->syncSelectionsFromDevice();
    }

    public function updatedSelectedGroupId(AssignDeviceGroup $action): void
    {
        $this->authorize('manageGroupsAndTags', $this->device);

        $group = $this->selectedGroupId ? DeviceGroup::find($this->selectedGroupId) : null;
        $action($this->device, $group);
        $this->device->refresh();
    }

    public function updatedSelectedTagIds(SyncDeviceTags $action): void
    {
        $this->authorize('manageGroupsAndTags', $this->device);

        $action($this->device, collect($this->selectedTagIds)->map(fn ($id) => (int) $id)->toArray());
        $this->device->load('tags');
    }

    public function resetEnrolment(): void
    {
        $this->authorize('resetEnrolment', $this->device);

        $this->device->resetEnrolment();
        $this->device->refresh();

        $this->dispatch('notify', message: 'Enrolment reset. Re-approve the device after running "rmm reenroll" on it.');
    }

    private function syncSelectionsFromDevice(): void
    {
        $this->selectedGroupId = $this->device->device_group_id ? (string) $this->device->device_group_id : '';
        $this->selectedTagIds = $this->device->tags->pluck('id')->map(fn ($id) => (string) $id)->toArray();
    }

    public function render(): View
    {
        return view('livewire.devices.details', [
            'apiKeyState' => $this->device->apiKeyState(),
            'apiKeyStateDetail' => $this->device->apiKeyStateDetail(),
            'operatingSystem' => $this->device->operatingSystem(),
            'totalRam' => $this->device->totalRamForHumans(),
            'allGroups' => DeviceGroup::query()->orderBy('name')->get(),
            'allTags' => Tag::query()->orderBy('name')->get(),
        ])->title(DeviceTab::Details->pageTitle($this->device));
    }
}
