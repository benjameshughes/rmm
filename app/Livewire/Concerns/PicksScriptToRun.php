<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Exceptions\ScriptCannotBeRunDirectly;
use App\Models\Script;
use Livewire\Attributes\Locked;

/**
 * Opens the shared Run modal for whichever script was picked from a list.
 * Needs RunsScriptOnDevices and EntersScriptParameterValues alongside.
 */
trait PicksScriptToRun
{
    #[Locked]
    public ?int $runScriptId = null;

    public function openRun(int $scriptId): void
    {
        $script = Script::query()->findOrFail($scriptId);
        throw_if($script->is_internal, ScriptCannotBeRunDirectly::internal($script));

        $this->runScriptId = $script->id;
        $this->reset('selectedDeviceIds');
        unset($this->runTargets, $this->runTargetGroups);
        $this->fillParameterValues();
        $this->showExecuteModal = true;
    }

    protected function scriptToRun(): ?Script
    {
        return $this->runScriptId === null ? null : Script::query()->find($this->runScriptId);
    }
}
