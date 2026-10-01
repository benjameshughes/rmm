<?php

declare(strict_types=1);

namespace App\Livewire\AlertRules;

use App\Enums\AlertMetric;
use App\Enums\AlertOperator;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $name = '';

    public string $metric = '';

    public string $operator = 'gt';

    public float $threshold = 90;

    public int $duration_minutes = 5;

    public string $severity = 'warning';

    public bool $showModal = false;

    public ?int $editingId = null;

    public function create(): void
    {
        $this->validate($this->validationRules());

        AlertRule::create([
            'name' => $this->name,
            'metric' => $this->metric,
            'operator' => $this->operator,
            'threshold' => $this->threshold,
            'duration_minutes' => $this->duration_minutes,
            'severity' => $this->severity,
        ]);

        $this->resetForm();
    }

    public function edit(AlertRule $rule): void
    {
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->metric = $rule->metric->value;
        $this->operator = $rule->operator->value;
        $this->threshold = $rule->threshold;
        $this->duration_minutes = $rule->duration_minutes;
        $this->severity = $rule->severity->value;
        $this->showModal = true;
    }

    public function update(): void
    {
        abort_unless($this->editingId !== null, 422);

        $this->validate($this->validationRules());

        $rule = AlertRule::findOrFail($this->editingId);
        $rule->update([
            'name' => $this->name,
            'metric' => $this->metric,
            'operator' => $this->operator,
            'threshold' => $this->threshold,
            'duration_minutes' => $this->duration_minutes,
            'severity' => $this->severity,
        ]);

        $this->resetForm();
    }

    public function toggleActive(AlertRule $rule): void
    {
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function delete(AlertRule $rule): void
    {
        $rule->delete();
    }

    public function resetForm(): void
    {
        $this->reset('name', 'metric', 'editingId', 'showModal');
        $this->operator = 'gt';
        $this->threshold = 90;
        $this->duration_minutes = 5;
        $this->severity = 'warning';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $rules = AlertRule::query()
            ->withCount('alerts')
            ->orderBy('name')
            ->get();

        return view('livewire.alert-rules.index', [
            'rules' => $rules,
            'metrics' => AlertMetric::cases(),
            'operators' => AlertOperator::cases(),
            'severities' => AlertSeverity::cases(),
        ]);
    }

    private function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'metric' => ['required', Rule::enum(AlertMetric::class)],
            'operator' => ['required', Rule::enum(AlertOperator::class)],
            'threshold' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'severity' => ['required', Rule::enum(AlertSeverity::class)],
        ];
    }
}
