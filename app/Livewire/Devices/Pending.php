<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Pending extends Component
{
    public function render(): View
    {
        $devices = Device::query()
            ->where('status', DeviceStatus::Pending)
            ->latest('created_at')
            ->get();

        return view('livewire.devices.pending', [
            'devices' => $devices,
            'enrolledHostnames' => $this->enrolledHostnames($devices),
        ]);
    }

    public function approve(int $deviceId): void
    {
        abort_unless(auth()->check(), 401);

        $device = Device::findOrFail($deviceId);

        if ($device->status !== DeviceStatus::Pending) {
            $this->dispatch('notify', message: 'Only pending devices can be approved');

            return;
        }

        $device->issueApiKey();
        $this->dispatch('notify', message: 'Device approved');
    }

    public function reject(int $deviceId): void
    {
        abort_unless(auth()->check(), 401);

        $device = Device::findOrFail($deviceId);
        $device->status = DeviceStatus::Revoked;
        $device->save();
        $this->dispatch('notify', message: 'Device rejected');
    }

    /**
     * Lower-cased hostnames of pending devices that already belong to an approved
     * or revoked device. A second machine claiming an enrolled hostname is suspicious.
     *
     * @param  Collection<int, Device>  $devices
     * @return array<string, true>
     */
    private function enrolledHostnames(Collection $devices): array
    {
        if ($devices->isEmpty()) {
            return [];
        }

        return Device::query()
            ->where('status', '!=', DeviceStatus::Pending)
            ->whereIn(new Expression('LOWER(hostname)'), $devices->map(fn (Device $device): string => Str::lower($device->hostname))->unique()->values())
            ->pluck('hostname')
            ->mapWithKeys(fn (string $hostname): array => [Str::lower($hostname) => true])
            ->all();
    }
}
