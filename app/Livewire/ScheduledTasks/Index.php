<?php

declare(strict_types=1);

namespace App\Livewire\ScheduledTasks;

use App\Actions\Schedule\RunScheduledTask;
use App\Enums\ScheduleTargetType;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\Tag;
use App\Rules\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $name = '';

    public ?int $script_id = null;

    public string $cron_expression = '0 2 * * *';

    public string $target_type = 'all';

    public ?int $target_id = null;

    public bool $showModal = false;

    public ?int $editingId = null;

    public function create(): void
    {
        abort_unless(auth()->check(), 401);

        $this->validate($this->validationRules());

        $task = ScheduledTask::create([
            'name' => $this->name,
            'script_id' => $this->script_id,
            'cron_expression' => $this->cron_expression,
            'target_type' => $this->target_type,
            'target_id' => $this->target_type === 'all' ? null : $this->target_id,
            'created_by' => auth()->id(),
        ]);

        $task->calculateNextRun();
        $this->resetForm();
    }

    public function edit(ScheduledTask $task): void
    {
        $this->editingId = $task->id;
        $this->name = $task->name;
        $this->script_id = $task->script_id;
        $this->cron_expression = $task->cron_expression;
        $this->target_type = $task->target_type->value;
        $this->target_id = $task->target_id;
        $this->showModal = true;
    }

    public function update(): void
    {
        abort_unless($this->editingId !== null, 422);

        $this->validate($this->validationRules());

        $task = ScheduledTask::findOrFail($this->editingId);
        $task->update([
            'name' => $this->name,
            'script_id' => $this->script_id,
            'cron_expression' => $this->cron_expression,
            'target_type' => $this->target_type,
            'target_id' => $this->target_type === 'all' ? null : $this->target_id,
        ]);

        $task->calculateNextRun();
        $this->resetForm();
    }

    public function toggleActive(ScheduledTask $task): void
    {
        $task->update(['is_active' => ! $task->is_active]);
    }

    public function runNow(ScheduledTask $task, RunScheduledTask $runner): void
    {
        $runner($task);
        $this->dispatch('command-queued');
    }

    public function delete(ScheduledTask $task): void
    {
        $task->delete();
    }

    public function resetForm(): void
    {
        $this->reset('name', 'script_id', 'target_id', 'editingId', 'showModal');
        $this->cron_expression = '0 2 * * *';
        $this->target_type = 'all';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $tasks = ScheduledTask::query()
            ->with(['script', 'createdBy'])
            ->orderBy('name')
            ->get();

        return view('livewire.scheduled-tasks.index', [
            'tasks' => $tasks,
            'scripts' => Script::query()->orderBy('name')->get(),
            'groups' => DeviceGroup::query()->orderBy('name')->get(),
            'tags' => Tag::query()->orderBy('name')->get(),
            'devices' => Device::query()->orderBy('hostname')->limit(50)->get(),
            'targetTypes' => ScheduleTargetType::cases(),
        ]);
    }

    private function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'script_id' => ['required', 'exists:scripts,id'],
            'cron_expression' => ['required', 'string', new CronExpression],
            'target_type' => ['required', Rule::enum(ScheduleTargetType::class)],
            'target_id' => ['nullable', 'integer', Rule::requiredIf($this->target_type !== ScheduleTargetType::All->value)],
        ];
    }
}
