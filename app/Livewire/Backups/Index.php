<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\DTOs\Backups\BackupOverviewRow;
use App\Models\Device;
use App\Queries\ServerBackupQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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
final class Index extends Component
{
    #[Url(as: 'show')]
    public string $filter = '';

    public int $renderedAt = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);
    }

    /**
     * Metrics reports broadcast every minute from every device; the page
     * redraws at most once per heartbeat_refresh_seconds for them, which is
     * plenty for "3 hours ago" ages and PC backups finishing.
     */
    #[On('echo-private:devices,DeviceUpdated')]
    public function refreshFromHeartbeat(): void
    {
        if (now()->getTimestamp() - $this->renderedAt < config('dashboard.heartbeat_refresh_seconds')) {
            $this->skipRender();
        }
    }

    #[On('echo-private:devices,ServerBackupsReported')]
    #[On('echo-private:devices,AlertChanged')]
    public function refresh(): void {}

    public function render(ServerBackupQueries $queries): View
    {
        $this->renderedAt = now()->getTimestamp();

        $rows = $queries->fleet();
        $needingAttention = $rows->filter(fn (BackupOverviewRow $row): bool => $row->needsAttention);

        return view('livewire.backups.index', [
            'rows' => $this->filter === 'attention' ? $needingAttention->values() : $rows,
            'total' => $rows->count(),
            'attentionCount' => $needingAttention->count(),
        ]);
    }
}
