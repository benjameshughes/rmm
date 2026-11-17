<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Enums\DeviceStatus;
use App\Events\DeviceApproved;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Pending extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    #[On('echo-private:devices,DeviceEnrolled')]
    #[On('echo-private:devices,DeviceUpdated')]
    public function refreshDevices(): void
    {
        $this->renderIsland(name: 'pending-devices');
        $this->skipRender();
    }

    /**
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return Device::query()
            ->where('status', DeviceStatus::Pending)
            ->latest('created_at')
            ->get();
    }

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.pending');
    }

    public function render(): View
    {
        return view('livewire.devices.pending');
    }

    public function approve(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('approve', $device);

        if ($device->status !== DeviceStatus::Pending) {
            $this->dispatch('notify', message: 'Only pending devices can be approved');

            return;
        }

        $device->issueApiKey();
        DeviceApproved::dispatch($device, auth()->user());
        $this->dispatch('notify', message: 'Device approved');
    }

    public function reject(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $this->authorize('reject', $device);

        $device->status = DeviceStatus::Revoked;
        $device->save();
        $this->dispatch('notify', message: 'Device rejected');
    }

    /**
     * Lower-cased hostnames of pending devices that already belong to an approved
     * or revoked device. A second machine claiming an enrolled hostname is suspicious.
     *
     * @return array<string, true>
     */
    #[Computed]
    public function enrolledHostnames(): array
    {
        if ($this->devices->isEmpty()) {
            return [];
        }

        return Device::query()
            ->where('status', '!=', DeviceStatus::Pending)
            ->whereIn(new Expression('LOWER(hostname)'), $this->devices->map(fn (Device $device): string => Str::lower($device->hostname))->unique()->values())
            ->pluck('hostname')
            ->mapWithKeys(fn (string $hostname): array => [Str::lower($hostname) => true])
            ->all();
    }
}
