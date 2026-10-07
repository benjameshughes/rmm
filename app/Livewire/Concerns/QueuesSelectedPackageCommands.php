<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Software\QueuePackageCommands;
use App\DTOs\Software\PackageCommandPlan;
use App\Enums\PackageAction;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Upgrade or uninstall the ticked packages: the bulk bar opens a modal that
 * says exactly how many commands go to how many devices, and confirming queues
 * them. The host says which installs the ticked rows reach.
 */
trait QueuesSelectedPackageCommands
{
    public bool $closeAppFirst = false;

    public bool $showPackageCommandModal = false;

    public string $plannedPackageAction = '';

    abstract protected function planSelectedPackages(PackageAction $action): PackageCommandPlan;

    abstract public function clearSelection(): void;

    public function planPackageCommands(string $action): void
    {
        $packageAction = PackageAction::tryFrom($action);
        abort_if($packageAction === null || $packageAction === PackageAction::Install, 422);

        $this->plannedPackageAction = $packageAction->value;
        $this->resetErrorBag();
        unset($this->packageCommandPlan);
        $this->showPackageCommandModal = true;
    }

    #[Computed]
    public function packageCommandPlan(): ?PackageCommandPlan
    {
        $action = PackageAction::tryFrom($this->plannedPackageAction);

        return $action === null ? null : $this->planSelectedPackages($action);
    }

    public function queuePlannedPackageCommands(QueuePackageCommands $queue): void
    {
        $plan = $this->packageCommandPlan;
        abort_if($plan === null || $plan->isEmpty(), 422);

        $plan->devices()->each(fn (Device $device) => $this->authorize('runCommands', $device));
        $queued = $queue($plan, auth()->user(), closeAppFirst: $this->closeAppFirst);

        $this->reset('showPackageCommandModal', 'plannedPackageAction');
        $this->clearSelection();
        $this->dispatch('command-queued');

        Flux::toast(
            text: $queued < $plan->commandCount() ? 'Devices whose agent is older than '.config('agent.parameters_min_version').' were skipped. Update their agent first.' : 'Each device re-checks its software once it has run.',
            heading: "{$plan->action->queuedHeading()}: {$queued} of {$plan->commandCount()} ".Str::plural('command', $plan->commandCount()),
            variant: $queued < $plan->commandCount() ? 'warning' : 'success',
        );
    }
}
