<?php

declare(strict_types=1);

namespace App\Livewire\Commands;

use App\Livewire\Concerns\CancelsCommands;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class Detail extends Component
{
    use CancelsCommands;

    public ?int $commandId = null;

    public bool $showModal = false;

    #[On('show-command')]
    public function show(int $commandId): void
    {
        $this->authorize('viewAny', Device::class);

        $this->commandId = $commandId;
        $this->showModal = true;
        unset($this->command);
    }

    /** @param array{commandId?: int} $event */
    #[On('echo-private:devices,CommandUpdated')]
    #[On('echo-private:devices,CommandProgressed')]
    public function refreshCommand(array $event): void
    {
        if (($event['commandId'] ?? null) === $this->commandId) {
            return;
        }

        $this->skipRender();
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
