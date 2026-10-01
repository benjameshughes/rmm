<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Schedule\RunScheduledTask;
use App\Models\ScheduledTask;
use Illuminate\Console\Command;

final class RunScheduledTasks extends Command
{
    protected $signature = 'schedule:run-tasks';

    protected $description = 'Check and execute due scheduled tasks';

    public function handle(RunScheduledTask $runner): int
    {
        $dueTasks = ScheduledTask::query()
            ->active()
            ->with('script')
            ->get()
            ->filter(fn (ScheduledTask $task): bool => $task->isDue());

        $dueTasks->each(function (ScheduledTask $task) use ($runner): void {
            $count = $runner($task);
            $this->info("Ran '{$task->name}' on {$count} devices.");
        });

        $this->info("Processed {$dueTasks->count()} scheduled tasks.");

        return self::SUCCESS;
    }
}
