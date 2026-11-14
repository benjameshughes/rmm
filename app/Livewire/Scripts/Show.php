<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\CancelsCommands;
use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\RunsScriptOnDevices;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
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
    public function refreshExecutions(): void {}

    protected function scriptToRun(): ?Script
    {
        return $this->script;
    }

    public function render(): View
    {
        $recentCommands = $this->script->commands()
            ->with('device')
            ->latest('queued_at')
            ->limit(10)
            ->get();

        return view('livewire.scripts.show', [
            'recentCommands' => $recentCommands,
            'availableDevices' => $this->showExecuteModal ? $this->runTargets : collect(),
            'runGroups' => $this->showExecuteModal ? $this->runTargetGroups : collect(),
        ]);
    }
}
