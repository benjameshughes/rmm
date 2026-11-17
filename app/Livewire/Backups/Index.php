<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\DTOs\Backups\BackupOverviewRow;
use App\Models\Device;
use App\Queries\ServerBackupQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every backup across the fleet on one page: each server's backup jobs and
 * each PC's profile backups, worst first.
 */
#[Layout('components.layouts.app')]
#[Title('Backups')]
#[Lazy]
final class Index extends Component
{
    #[Url(as: 'show')]
    public string $filter = '';

    public int $renderedAt = 0;

    private ServerBackupQueries $queries;

    public function boot(ServerBackupQueries $queries): void
    {
        $this->queries = $queries;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * Metrics reports broadcast every minute from every device; the list
     * redraws at most once per heartbeat_refresh_seconds for them, which is
     * plenty for "3 hours ago" ages and PC backups finishing.
     */
    #[On('echo-private:devices,DeviceUpdated')]
    public function refreshFromHeartbeat(): void
    {
        if (now()->getTimestamp() - $this->renderedAt < config('dashboard.heartbeat_refresh_seconds')) {
            $this->skipRender();

            return;
        }

        $this->refresh();
    }

    #[On('echo-private:devices,ServerBackupsReported')]
    #[On('echo-private:devices,AlertChanged')]
    public function refresh(): void
    {
        $this->renderedAt = now()->getTimestamp();
        $this->renderIsland(name: 'backups');
        $this->skipRender();
    }

    /**
     * @return Collection<int, BackupOverviewRow>
     */
    #[Computed]
    public function fleet(): Collection
    {
        return $this->queries->fleet();
    }

    /**
     * @return Collection<int, BackupOverviewRow>
     */
    #[Computed]
    public function needingAttention(): Collection
    {
        return $this->fleet->filter(fn (BackupOverviewRow $row): bool => $row->needsAttention)->values();
    }

    /**
     * @return Collection<int, BackupOverviewRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->filter === 'attention' ? $this->needingAttention : $this->fleet;
    }

    #[Computed]
    public function attentionTone(): string
    {
        return $this->needingAttention->isNotEmpty() ? 'text-red-500' : 'text-zinc-400 dark:text-zinc-500';
    }

    public function placeholder(): View
    {
        return view('livewire.backups.placeholders.index');
    }

    public function render(): View
    {
        return view('livewire.backups.index');
    }
}
