<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Agent extends Component
{
    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.agent');
    }

    public function render(): View
    {
        return view('livewire.devices.agent', [
            'downloadUrl' => route('agent.download'),
            'linuxInstallCommand' => 'curl -fsSL '.route('agent.download.linux').' | sudo bash',
        ]);
    }
}
