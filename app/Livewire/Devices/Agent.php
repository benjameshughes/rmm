<?php

namespace App\Livewire\Devices;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Agent extends Component
{
    public function render(): mixed
    {
        $downloadUrl = route('agent.download');

        return view('livewire.devices.agent', [
            'downloadUrl' => $downloadUrl,
        ]);
    }
}

