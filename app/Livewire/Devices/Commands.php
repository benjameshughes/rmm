<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Enums\DeviceTab;
use App\Livewire\Concerns\CancelsCommands;
use App\Models\Device;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Commands extends Component
{
    use CancelsCommands;
    use WithPagination;

    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    public function render(): View
    {
        return view('livewire.devices.commands', [
            'commands' => $this->device->commands()
                ->with(['script', 'queuedBy'])
                ->latest('queued_at')
                ->latest('id')
                ->paginate(config('commands.per_page')),
        ])->title(DeviceTab::Commands->pageTitle($this->device));
    }
}
