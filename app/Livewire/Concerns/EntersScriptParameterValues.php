<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Script\ValidateScriptParameterValues;
use App\DTOs\ScriptParameter;
use App\Models\Script;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Values typed into a script's parameter inputs, keyed by parameter name.
 */
trait EntersScriptParameterValues
{
    /** @var array<string, string|bool> */
    public array $parameterValues = [];

    /** The script whose parameters the form is currently showing. */
    abstract protected function parameterScript(): ?Script;

    /** @return Collection<int, ScriptParameter> */
    #[Computed]
    public function parameterFields(): Collection
    {
        return $this->parameterScript()?->parameters ?? collect();
    }

    /**
     * Livewire only clears errors after its own validate() passes, so errors
     * from an earlier failed attempt are cleared here first.
     *
     * @return array<string, string>
     */
    protected function validatedParameterValues(ValidateScriptParameterValues $validateParameters, Script $script): array
    {
        $this->resetValidation();

        return $validateParameters($script, $this->parameterValues);
    }

    /**
     * @param  array<string, string>  $storedValues  Values saved earlier, such as a schedule's
     */
    protected function fillParameterValues(array $storedValues = []): void
    {
        unset($this->parameterFields);

        $this->parameterValues = $this->parameterFields
            ->mapWithKeys(fn (ScriptParameter $parameter): array => [$parameter->name => $parameter->inputValue($storedValues[$parameter->name] ?? null)])
            ->all();

        $this->resetValidation();
    }
}
