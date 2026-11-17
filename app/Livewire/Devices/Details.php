<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Device\AssignDeviceGroup;
use App\Actions\Device\SyncDeviceTags;
use App\DTOs\Inventory\SystemInventory;
use App\Enums\ApiKeyState;
use App\Enums\DeviceTab;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Details extends Component
{
    public Device $device;

    public ?string $selectedGroupId = null;

    public array $selectedTagIds = [];

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load(['latestMetric.networkMetrics', 'latestInventory', 'group', 'tags']);
        $this->syncSelectionsFromDevice();
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('echo-private:devices.{device.id},SystemInventorySynced')]
    public function refreshDevice(): void
    {
        $this->device->refresh()->load(['latestMetric.networkMetrics', 'latestInventory']);
        $this->syncSelectionsFromDevice();
        $this->renderIsland(name: 'details');
        $this->skipRender();
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

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.details');
    }

    public function render(): View
    {
        return view('livewire.devices.details')->title(DeviceTab::Details->pageTitle($this->device));
    }

    #[Computed]
    public function hasSystemInventory(): bool
    {
        return $this->device->hasSystemInventory();
    }

    #[Computed]
    public function inventory(): ?SystemInventory
    {
        return $this->hasSystemInventory ? $this->device->latestInventory?->snapshot() : null;
    }

    #[Computed]
    public function apiKeyState(): ApiKeyState
    {
        return $this->device->apiKeyState();
    }

    #[Computed]
    public function apiKeyStateDetail(): ?string
    {
        return $this->device->apiKeyStateDetail();
    }

    #[Computed]
    public function operatingSystem(): ?string
    {
        return $this->device->operatingSystem();
    }

    #[Computed]
    public function totalRam(): ?string
    {
        return $this->device->totalRamForHumans();
    }

    #[Computed]
    public function cumulativeNetworkCounters(): bool
    {
        return $this->device->platform()->hasCumulativeNetworkCounters();
    }

    /** @return Collection<int, DeviceGroup> */
    #[Computed]
    public function allGroups(): Collection
    {
        return DeviceGroup::query()->orderBy('name')->get();
    }

    /** @return Collection<int, Tag> */
    #[Computed]
    public function allTags(): Collection
    {
        return Tag::query()->orderBy('name')->get();
    }
}
