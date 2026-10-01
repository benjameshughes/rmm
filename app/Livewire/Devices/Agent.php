<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Agent extends Component
{
    public function render(): View
    {
        return view('livewire.devices.agent', [
            'downloadUrl' => route('agent.download'),
        ]);
    }
}
