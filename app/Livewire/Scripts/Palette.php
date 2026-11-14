<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Livewire\Concerns\EntersScriptParameterValues;
use App\Livewire\Concerns\PicksScriptToRun;
use App\Livewire\Concerns\RunsScriptOnDevices;
use App\Models\Device;
use App\Queries\ScriptQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cmd+K: find a script and run it. On a device page it runs on that device through the
 * header's Run Script flow; anywhere else it opens the shared Run modal to pick devices.
 */
final class Palette extends Component
{
    use EntersScriptParameterValues;
    use PicksScriptToRun;
    use RunsScriptOnDevices;

    #[Locked]
    public ?int $deviceId = null;

    public bool $showPalette = false;

    public string $paletteSearch = '';

    public function mount(?Device $device = null): void
    {
        $this->deviceId = $device?->id;
    }

    #[On('open-script-palette')]
    public function open(): void
    {
        $this->reset('paletteSearch');
        $this->showPalette = true;
    }

    public function pick(int $scriptId): void
    {
        $this->showPalette = false;

        if ($this->deviceId !== null) {
            $this->dispatch('run-device-script', scriptId: $scriptId);

            return;
        }

        $this->openRun($scriptId);
    }

    public function render(ScriptQueries $scripts): View
    {
        $device = $this->deviceId === null ? null : Device::query()->find($this->deviceId);

        return view('livewire.scripts.palette', [
            'scripts' => $this->showPalette ? $scripts->palette($device?->platform(), $this->paletteSearch) : collect(),
            'scopeLabel' => $device === null ? 'Run a script on any device...' : "Run a script on {$device->hostname}...",
            'runScript' => $this->showExecuteModal ? $this->scriptToRun() : null,
            'availableDevices' => $this->showExecuteModal ? $this->runTargets : collect(),
            'runGroups' => $this->showExecuteModal ? $this->runTargetGroups : collect(),
        ]);
    }
}
