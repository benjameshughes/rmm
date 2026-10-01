<?php

declare(strict_types=1);

namespace App\Livewire\Commands;

use App\Models\DeviceCommand;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class Detail extends Component
{
    public ?int $commandId = null;

    public bool $showModal = false;

    #[On('show-command')]
    public function show(int $commandId): void
    {
        $this->commandId = $commandId;
        $this->showModal = true;
        unset($this->command);
    }

    #[Computed]
    public function command(): ?DeviceCommand
    {
        if ($this->commandId === null) {
            return null;
        }

        return DeviceCommand::query()
            ->with(['device', 'script', 'queuedBy'])
            ->find($this->commandId);
    }

    public function render(): View
    {
        return view('livewire.commands.detail');
    }
}
