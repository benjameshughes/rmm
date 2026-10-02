<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Enums\ScriptCategory;
use App\Enums\ScriptParameterType;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Livewire\Concerns\DefinesScriptParameters;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Create extends Component
{
    use DefinesScriptParameters;

    public string $name = '';

    public string $description = '';

    public string $category = '';

    public string $platform = '';

    public string $script_type = '';

    public string $script_content = '';

    public int $timeout_seconds = 300;

    public bool $requires_admin = false;

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', Rule::enum(ScriptCategory::class)],
            'platform' => ['required', Rule::enum(ScriptPlatform::class)],
            'script_type' => ['required', Rule::enum(ScriptType::class)],
            'script_content' => ['required', 'string'],
            'timeout_seconds' => ['required', 'integer', 'min:10', 'max:7200'],
            'requires_admin' => ['boolean'],
            ...$this->parameterRules(),
        ], $this->parameterMessages());

        $script = Script::create([
            ...Arr::except($validated, 'parameterRows'),
            'parameters' => $this->parameterDefinitions(),
            'is_system' => false,
        ]);

        $this->redirect(route('scripts.show', $script), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.scripts.create', [
            'categories' => ScriptCategory::cases(),
            'platforms' => ScriptPlatform::cases(),
            'scriptTypes' => ScriptType::cases(),
            'parameterTypes' => ScriptParameterType::cases(),
        ]);
    }
}
