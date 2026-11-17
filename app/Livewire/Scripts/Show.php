<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\CancelsCommands;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\RunsScriptOnDevices;
use App\Models\DeviceCommand;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Lazy]
final class Show extends Component
{
    use CancelsCommands;
    use EntersScriptParameterValues;
    use RunsScriptOnDevices;

    public Script $script;

    public function mount(Script $script): void
    {
        $this->script = $script;
        $this->fillParameterValues();
    }

    #[On('echo-private:devices,CommandUpdated')]
    #[On('command-queued')]
    public function refreshExecutions(): void
    {
        $this->renderIsland(name: 'executions');
        $this->skipRender();
    }

    protected function scriptToRun(): ?Script
    {
        return $this->script;
    }

    /**
     * @return Collection<int, DeviceCommand>
     */
    #[Computed]
    public function recentCommands(): Collection
    {
        return $this->script->commands()
            ->with('device')
            ->latest('queued_at')
            ->limit(10)
            ->get();
    }

    public function placeholder(): View
    {
        return view('livewire.scripts.placeholders.show');
    }

    public function render(): View
    {
        return view('livewire.scripts.show', [
            'availableDevices' => $this->showExecuteModal ? $this->runTargets : collect(),
            'runGroups' => $this->showExecuteModal ? $this->runTargetGroups : collect(),
        ]);
    }
}
