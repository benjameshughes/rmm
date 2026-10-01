<?php

declare(strict_types=1);

namespace App\Livewire\Scripts;

use App\Enums\ScriptCategory;
use App\Enums\ScriptPlatform;
use App\Enums\ScriptType;
use App\Models\Script;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Edit extends Component
{
    public Script $script;

    public string $name = '';

    public string $description = '';

    public string $category = '';

    public string $platform = '';

    public string $script_type = '';

    public string $script_content = '';

    public int $timeout_seconds = 300;

    public bool $requires_admin = false;

    public function mount(Script $script): void
    {
        abort_if($script->is_system, 403);

        $this->script = $script;
        $this->name = $script->name;
        $this->description = $script->description ?? '';
        $this->category = $script->category->value;
        $this->platform = $script->platform->value;
        $this->script_type = $script->script_type->value;
        $this->script_content = $script->script_content;
        $this->timeout_seconds = $script->timeout_seconds;
        $this->requires_admin = $script->requires_admin;
    }

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
        ]);

        $this->script->update($validated);

        $this->redirect(route('scripts.show', $this->script), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.scripts.edit', [
            'categories' => ScriptCategory::cases(),
            'platforms' => ScriptPlatform::cases(),
            'scriptTypes' => ScriptType::cases(),
        ]);
    }
}
