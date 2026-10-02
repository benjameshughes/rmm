<?php

declare(strict_types=1);

namespace App\Livewire\ScheduledTasks;

use App\Actions\Schedule\RunScheduledTask;
use App\Enums\ScheduledTaskAction;
use App\Enums\ScheduleTargetType;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\ScheduledTask;
use App\Models\Script;
use App\Models\Tag;
use App\Rules\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $name = '';

    public string $action = 'run_script';

    public ?int $script_id = null;

    public string $cron_expression = '0 2 * * *';

    public string $target_type = 'all';

    public ?int $target_id = null;

    public bool $showModal = false;

    public ?int $editingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ScheduledTask::class);
    }

    public function create(): void
    {
        $this->authorize('create', ScheduledTask::class);

        $this->validate($this->validationRules(), $this->validationMessages());

        $task = ScheduledTask::create([
            'name' => $this->name,
            'action' => $this->action,
            'script_id' => $this->requiresScript ? $this->script_id : null,
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
        $this->authorize('update', $task);

        $this->editingId = $task->id;
        $this->name = $task->name;
        $this->action = $task->action->value;
        $this->script_id = $task->script_id;
        $this->cron_expression = $task->cron_expression;
        $this->target_type = $task->target_type->value;
        $this->target_id = $task->target_id;
        $this->showModal = true;
    }

    public function update(): void
    {
        abort_unless($this->editingId !== null, 422);

        $this->validate($this->validationRules(), $this->validationMessages());

        $task = ScheduledTask::findOrFail($this->editingId);
        $this->authorize('update', $task);

        $task->update([
            'name' => $this->name,
            'action' => $this->action,
            'script_id' => $this->requiresScript ? $this->script_id : null,
            'cron_expression' => $this->cron_expression,
            'target_type' => $this->target_type,
            'target_id' => $this->target_type === 'all' ? null : $this->target_id,
        ]);

        $task->calculateNextRun();
        $this->resetForm();
    }

    public function toggleActive(ScheduledTask $task): void
    {
        $this->authorize('update', $task);
        $task->update(['is_active' => ! $task->is_active]);
    }

    public function runNow(ScheduledTask $task, RunScheduledTask $runner): void
    {
        $this->authorize('run', $task);
        $runner($task);
        $this->dispatch('command-queued');
    }

    public function delete(ScheduledTask $task): void
    {
        $this->authorize('delete', $task);
        $task->delete();
    }

    public function resetForm(): void
    {
        $this->reset('name', 'script_id', 'target_id', 'editingId', 'showModal');
        $this->action = 'run_script';
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
            'actions' => ScheduledTaskAction::cases(),
            'targetTypes' => ScheduleTargetType::cases(),
        ]);
    }

    /**
     * Wake schedules have no script, so the script picker only applies to script runs.
     */
    #[Computed]
    public function requiresScript(): bool
    {
        return ScheduledTaskAction::tryFrom($this->action)?->requiresScript() ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    private function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'action' => ['required', Rule::enum(ScheduledTaskAction::class)],
            'script_id' => ['nullable', 'integer', Rule::requiredIf($this->requiresScript), 'exists:scripts,id'],
            'cron_expression' => ['required', 'string', new CronExpression],
            'target_type' => ['required', Rule::enum(ScheduleTargetType::class)],
            'target_id' => ['nullable', 'integer', Rule::requiredIf($this->target_type !== ScheduleTargetType::All->value)],
        ];
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'action.required' => 'Choose what this schedule should do.',
            'action.enum' => 'Choose what this schedule should do.',
            'script_id.required' => 'Choose a script to run.',
            'script_id.exists' => 'That script no longer exists.',
        ];
    }
}
