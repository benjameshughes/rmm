<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\DeviceAttention;
use App\Models\AuditLog;
use App\Models\Device;
use App\Queries\AgentVersionQueries;
use App\Queries\DashboardQueries;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
final class Dashboard extends Component
{
    #[Locked]
    public int $renderedAt = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);

        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * Heartbeats arrive every few seconds across the fleet, so they only redraw a dashboard that has gone stale.
     */
    #[On('echo-private:devices,DeviceUpdated')]
    public function refreshFromHeartbeat(): void
    {
        if (now()->getTimestamp() - $this->renderedAt < config('dashboard.heartbeat_refresh_seconds')) {
            $this->skipRender();

            return;
        }

        $this->renderedAt = now()->getTimestamp();
    }

    #[On('echo-private:devices,DeviceEnrolled')]
    #[On('echo-private:devices,AlertChanged')]
    #[On('echo-private:devices,LatestAgentVersionChanged')]
    #[On('echo-private:audit,AuditLogged')]
    public function refresh(): void
    {
        $this->renderedAt = now()->getTimestamp();
    }

    public function render(DashboardQueries $dashboard, AgentVersionQueries $agentVersions): View
    {
        $fleet = $dashboard->fleet();
        $latestAgentVersion = $agentVersions->latest();
        [$needsAttention, $worthKnowing] = $dashboard->needsAttention($fleet, $latestAgentVersion)
            ->partition(fn (DeviceAttention $attention): bool => $attention->severity()->isActionable());

        return view('livewire.dashboard', [
            'summary' => $dashboard->summary(),
            'printStations' => $dashboard->printStations($fleet),
            'needsAttention' => $needsAttention->values(),
            'worthKnowing' => $worthKnowing->values(),
            'fullestDisks' => $dashboard->fullestDisks($fleet, config('dashboard.disk_rows')),
            'diskCount' => $fleet->count(),
            'busiest' => $dashboard->busiest($fleet, config('dashboard.busiest_devices')),
            'recentAlerts' => $dashboard->recentAlerts(config('dashboard.recent_alerts')),
            'recentActivity' => auth()->user()->can('viewAny', AuditLog::class) ? $dashboard->recentActivity(config('dashboard.recent_activity')) : collect(),
            'latestAgentVersion' => $latestAgentVersion,
        ]);
    }
}
