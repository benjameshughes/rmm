<?php

declare(strict_types=1);

namespace App\Livewire\Alerts;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Lazy]
final class Index extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $severityFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Alert::class);
    }

    #[On('echo-private:devices,AlertChanged')]
    public function refreshAlerts(): void
    {
        $this->renderIsland(name: 'alerts');
        $this->skipRender();
    }

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
        $this->authorize('update', $alert);
        $alert->acknowledge(auth()->user());
    }

    public function resolve(Alert $alert): void
    {
        $this->authorize('update', $alert);
        $alert->resolve();
    }

    /** @return LengthAwarePaginator<int, Alert> */
    #[Computed]
    public function alerts(): LengthAwarePaginator
    {
        return Alert::query()
            ->with(['device', 'alertRule'])
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->severityFilter !== '', fn (Builder $q) => $q->where('severity', $this->severityFilter))
            ->latest('triggered_at')
            ->paginate(20);
    }

    public function placeholder(): View
    {
        return view('livewire.alerts.placeholders.index');
    }

    public function render(): View
    {
        return view('livewire.alerts.index', [
            'statuses' => AlertStatus::cases(),
            'severities' => AlertSeverity::cases(),
        ]);
    }
}
