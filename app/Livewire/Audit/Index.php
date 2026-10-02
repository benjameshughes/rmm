<?php

declare(strict_types=1);

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
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
    public function refreshAuditLog(): void {}

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

    public function render(): View
    {
        $auditLogs = AuditLog::query()
            ->with('user')
            ->byActor($this->userFilter)
            ->when($this->actionFilter !== '', fn (Builder $q) => $q->where('action', $this->actionFilter))
            ->search($this->search)
            ->latest('id')
            ->paginate(config('audit.per_page'));

        return view('livewire.audit.index', [
            'auditLogs' => $auditLogs,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => AuditAction::cases(),
        ]);
    }
}
