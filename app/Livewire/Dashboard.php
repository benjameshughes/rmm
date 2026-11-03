<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Dashboard\BuildAttentionChips;
use App\Actions\Dashboard\BuildFleetGrid;
use App\Actions\Dashboard\BuildHeroMeters;
use App\DTOs\Dashboard\AttentionRow;
use App\DTOs\Dashboard\HeroFigures;
use App\DTOs\Dashboard\Ring;
use App\DTOs\Dashboard\RingSegment;
use App\DTOs\DeviceAttention;
use App\Models\Device;
use App\Queries\AgentVersionQueries;
use App\Queries\DashboardChartQueries;
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
    public function refresh(): void
    {
        $this->renderedAt = now()->getTimestamp();
    }

    public function render(DashboardQueries $dashboard, DashboardChartQueries $charts, AgentVersionQueries $agentVersions, BuildAttentionChips $buildChips, BuildFleetGrid $buildGrid, BuildHeroMeters $buildMeters): View
    {
        $fleet = $dashboard->fleet();
        $latestAgentVersion = $agentVersions->latest();
        [$needsAttention, $worthKnowing] = $dashboard->needsAttention($fleet, $latestAgentVersion)
            ->partition(fn (DeviceAttention $attention): bool => $attention->severity()->isActionable());
        $summary = $dashboard->summary();
        $busiest = $dashboard->busiest($fleet, config('dashboard.busiest_devices'));
        $sparklines = $charts->cpuSparklines($busiest->modelKeys());
        $alertsPerBucket = $charts->alertsPerBucket();
        $offlineDevices = $dashboard->offlineDevices($fleet);
        $agentRing = $dashboard->agentRing($fleet, $latestAgentVersion);
        $diskRing = $dashboard->diskRing($fleet);
        $toRow = fn (DeviceAttention $attention): AttentionRow => new AttentionRow(attention: $attention, chips: $buildChips($attention, $latestAgentVersion));

        return view('livewire.dashboard', [
            'updatedAt' => now()->inDisplayTimezone()->format('H:i'),
            'summary' => $summary,
            'hero' => new HeroFigures(
                online: $summary['online'],
                total: $summary['total'],
                offline: $summary['offline'],
                openAlerts: $summary['openAlerts'],
                fullDisks: $needsAttention->sum(fn (DeviceAttention $item): int => $item->disks->count()),
                failedUnits: $needsAttention->sum(fn (DeviceAttention $item): int => $item->failedUnits->count()),
                alertTrend: $charts->alertTrend(),
            ),
            'onlineRing' => new Ring(collect([
                new RingSegment(label: 'Online', value: $summary['online'], strokeClass: 'stroke-green-500'),
                new RingSegment(label: 'Offline', value: $summary['offline'], strokeClass: 'stroke-red-500'),
                new RingSegment(label: 'Other', value: $summary['total'] - $summary['online'] - $summary['offline'], strokeClass: 'stroke-zinc-200 dark:stroke-zinc-700'),
            ])),
            'offlineNames' => $offlineDevices->take(config('dashboard.offline_names')),
            'hiddenOfflineCount' => max(0, $offlineDevices->count() - config('dashboard.offline_names')),
            'alertMiniBars' => $charts->alertMiniBars($alertsPerBucket),
            'heroMeters' => $buildMeters($fleet, $needsAttention),
            'alertsPerBucket' => $alertsPerBucket,
            'isQuietDay' => $alertsPerBucket->sum('total') === 0,
            'fleetGrid' => $buildGrid($fleet, $needsAttention),
            'fleetLoad' => $charts->fleetLoad($fleet->modelKeys()),
            'agentRing' => $agentRing,
            'agentsBehind' => $agentRing->problemTotal(),
            'diskRing' => $diskRing,
            'disksOverWarning' => $diskRing->problemTotal(),
            'needsAttention' => $needsAttention->values(),
            'attentionRows' => $needsAttention->take(config('dashboard.attention_rows'))->map($toRow)->values(),
            'hiddenAttentionCount' => max(0, $needsAttention->count() - config('dashboard.attention_rows')),
            'worthKnowing' => $worthKnowing->values(),
            'worthKnowingRows' => $worthKnowing->take(config('dashboard.worth_knowing_rows'))->map($toRow)->values(),
            'fullestDisks' => $dashboard->fullestDisks($fleet, config('dashboard.disk_rows')),
            'diskCount' => $fleet->count(),
            'busiest' => $busiest,
            'busiestRows' => $busiest->map(fn (Device $device): array => ['device' => $device, 'sparkline' => $sparklines->get($device->id)])->values(),
        ]);
    }
}
