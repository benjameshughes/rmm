<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
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

    public string $userFilter = '';

    public string $actionFilter = '';

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }

    #[On('echo-private:audit,AuditLogged')]
    public function refreshAuditLog(): void
    {
        $this->renderIsland(name: 'audit-log');
        $this->skipRender();
    }

    public function updatingUserFilter(): void
    {
        $this->resetPage();
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, AuditLog> */
    #[Computed]
    public function auditLogs(): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with('user')
            ->byActor($this->userFilter)
            ->when($this->actionFilter !== '', fn (Builder $q) => $q->where('action', $this->actionFilter))
            ->search($this->search)
            ->latest('id')
            ->paginate(config('audit.per_page'));
    }

    public function placeholder(): View
    {
        return view('livewire.audit.placeholders.index');
    }

    public function render(): View
    {
        return view('livewire.audit.index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => AuditAction::cases(),
        ]);
    }
}
