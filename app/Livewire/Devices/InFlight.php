<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\DTOs\InFlightCommands;
use App\Livewire\Concerns\CancelsCommands;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What a device is running or about to run, under its header on every tab, so a
 * Run now on any tab shows straight away without a trip to Commands.
 */
final class InFlight extends Component
{
    use CancelsCommands;

    public Device $device;

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void
    {
        $this->device->refresh();
    }

    /**
     * Only devices that take commands can have any in flight; the device is already loaded, so it is handed to each command.
     */
    private function inFlightCommands(): InFlightCommands
    {
        if (! auth()->user()->can('runCommands', $this->device)) {
            return InFlightCommands::none();
        }

        $commands = $this->device->inFlightCommands()->with('script')->get()
            ->each(fn (DeviceCommand $command) => $command->setRelation('device', $this->device));

        return InFlightCommands::from($commands, $this->device);
    }

    public function render(): View
    {
        return view('livewire.devices.in-flight', [
            'inFlight' => $this->inFlightCommands(),
        ]);
    }
}
