<?php

declare(strict_types=1);

namespace App\Livewire\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $severityFilter = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSeverityFilter(): void
    {
        $this->resetPage();
    }

    public function acknowledge(Alert $alert): void
    {
        abort_unless(auth()->check(), 401);
        $alert->acknowledge(auth()->user());
    }

    public function resolve(Alert $alert): void
    {
        $alert->resolve();
    }

    public function render(): View
    {
        $alerts = Alert::query()
            ->with(['device', 'alertRule'])
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->severityFilter !== '', fn (Builder $q) => $q->where('severity', $this->severityFilter))
            ->latest('triggered_at')
            ->paginate(20);

        return view('livewire.alerts.index', [
            'alerts' => $alerts,
            'statuses' => AlertStatus::cases(),
            'severities' => AlertSeverity::cases(),
        ]);
    }
}
