<?php

namespace App\Livewire\Devices;

use App\Models\Device;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Pending extends Component
{
    public function render(): mixed
    {
        $devices = Device::query()
            ->where('status', Device::STATUS_PENDING)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('livewire.devices.pending', [
            'devices' => $devices,
        ]);
    }

    public function approve(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $device->issueApiKey();
        $this->dispatch('notify', message: 'Device approved');
    }

    public function reject(int $deviceId): void
    {
        $device = Device::findOrFail($deviceId);
        $device->status = Device::STATUS_REVOKED;
        $device->save();
        $this->dispatch('notify', message: 'Device rejected');
    }
}

