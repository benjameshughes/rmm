<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Dashboard\BuildAttentionChips;
use App\Actions\Dashboard\BuildFleetGrid;
use App\Actions\Dashboard\BuildHeroMeters;
use App\DTOs\Dashboard\AttentionRow;
use App\DTOs\Dashboard\BusiestRow;
use App\DTOs\Dashboard\FleetGroup;
use App\DTOs\Dashboard\HeroFigures;
use App\DTOs\Dashboard\Ring;
use App\DTOs\Dashboard\RingSegment;
use App\DTOs\DeviceAttention;
use App\Enums\ChartInk;
use App\Enums\LoadLevel;
use App\Enums\SignalColor;
use App\Models\Device;
use App\Queries\AgentVersionQueries;
use App\Queries\DashboardChartQueries;
use App\Queries\DashboardQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Dashboard')]
#[Lazy]
final class Dashboard extends Component
{
    #[Locked]
    public int $renderedAt = 0;

    private DashboardQueries $dashboard;

    private DashboardChartQueries $charts;

    private AgentVersionQueries $agentVersions;

    private BuildFleetGrid $buildGrid;

    public function boot(DashboardQueries $dashboard, DashboardChartQueries $charts, AgentVersionQueries $agentVersions, BuildFleetGrid $buildGrid): void
    {
        $this->buildGrid = $buildGrid;
        $this->dashboard = $dashboard;
        $this->charts = $charts;
        $this->agentVersions = $agentVersions;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Device::class);

        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * Heartbeats arrive every few seconds across the fleet, so they only redraw a dashboard that has gone stale.
     * The fleet grid and charts are islands that redraw with it.
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

    /** @return EloquentCollection<int, Device> */
    #[Computed]
    public function fleet(): EloquentCollection
    {
        return $this->dashboard->fleet();
    }

    #[Computed]
    public function latestAgentVersion(): ?string
    {
        return $this->agentVersions->latest();
    }

    /**
     * What needs acting on, then what is only worth knowing.
     *
     * @return Collection<int, Collection<int, DeviceAttention>>
     */
    #[Computed]
    public function attention(): Collection
    {
        return $this->dashboard->needsAttention($this->fleet, $this->latestAgentVersion)
            ->partition(fn (DeviceAttention $attention): bool => $attention->severity()->isActionable());
    }

    /** @return Collection<int, FleetGroup> */
    #[Computed]
    public function fleetGrid(): Collection
    {
        return ($this->buildGrid)($this->fleet, $this->attention->first());
    }

    /** @return Collection<int, array{time: string, warning: int, critical: int, total: int}> */
    #[Computed]
    public function alertsPerBucket(): Collection
    {
        return $this->charts->alertsPerBucket();
    }

    #[Computed]
    public function isQuietDay(): bool
    {
        return $this->alertsPerBucket->sum('total') === 0;
    }

    /** @return Collection<int, array{time: string, cpu: ?float, ram: ?float, threshold: int}> */
    #[Computed]
    public function fleetLoad(): Collection
    {
        return $this->charts->fleetLoad($this->fleet->modelKeys());
    }

    public function placeholder(): View
    {
        return view('livewire.dashboard.placeholders.index');
    }

    public function render(BuildAttentionChips $buildChips, BuildHeroMeters $buildMeters): View
    {
        $fleet = $this->fleet;
        $latestAgentVersion = $this->latestAgentVersion;
        [$needsAttention, $worthKnowing] = $this->attention;
        $summary = $this->dashboard->summary();
        $busiest = $this->dashboard->busiest($fleet, config('dashboard.busiest_devices'));
        $sparklines = $this->charts->cpuSparklines($busiest->modelKeys());
        $offlineDevices = $this->dashboard->offlineDevices($fleet);
        $agentRing = $this->dashboard->agentRing($fleet, $latestAgentVersion);
        $diskRing = $this->dashboard->diskRing($fleet);
        $toRow = fn (DeviceAttention $attention): AttentionRow => new AttentionRow(
            attention: $attention,
            device: $attention->device,
            severity: $attention->severity(),
            statusLabel: $attention->device->statusLabel(),
            lastSeen: $attention->device->lastSeenForHumans(),
            chips: $buildChips($attention, $latestAgentVersion),
        );

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
                alertTrend: $this->charts->alertTrend(),
            ),
            'onlineRing' => new Ring(collect([
                new RingSegment(label: 'Online', value: $summary['online'], ink: ChartInk::Online),
                new RingSegment(label: 'Offline', value: $summary['offline'], ink: ChartInk::Critical),
                new RingSegment(label: 'Other', value: $summary['total'] - $summary['online'] - $summary['offline'], ink: ChartInk::Faint),
            ])),
            'offlineNames' => $offlineDevices->take(config('dashboard.offline_names')),
            'hiddenOfflineCount' => max(0, $offlineDevices->count() - config('dashboard.offline_names')),
            'alertMiniBars' => $this->charts->alertMiniBars($this->alertsPerBucket),
            'heroMeters' => $buildMeters($fleet, $needsAttention),
            'agentRing' => $agentRing,
            'agentsCurrent' => $agentRing->segments->first()->value,
            'agentsBehindLabel' => "{$agentRing->problemTotal()} behind",
            'diskRing' => $diskRing,
            'disksOverWarning' => $diskRing->problemTotal(),
            'disksOverWarningClass' => SignalColor::Amber->figureClass($diskRing->problemTotal()),
            'needsAttention' => $needsAttention->values(),
            'attentionRows' => $needsAttention->take(config('dashboard.attention_rows'))->map($toRow)->values(),
            'hiddenAttentionCount' => max(0, $needsAttention->count() - config('dashboard.attention_rows')),
            'worthKnowing' => $worthKnowing->values(),
            'worthKnowingRows' => $worthKnowing->take(config('dashboard.worth_knowing_rows'))->map($toRow)->values(),
            'fullestDisks' => $this->dashboard->fullestDisks($fleet, config('dashboard.disk_rows')),
            'diskLinkLabel' => "All {$fleet->count()} disks",
            'disksLabel' => 'Disks >'.config('devices.disk.warning_percent').'%',
            'disksRingCaption' => 'over '.config('devices.disk.warning_percent').'%',
            'busiest' => $busiest,
            'busiestRows' => $busiest->map(fn (Device $device): BusiestRow => new BusiestRow(
                device: $device,
                sparkline: $sparklines->get($device->id),
                cpu: $device->latestMetric->cpuRoundedForHumans(),
                cpuLevel: LoadLevel::fromPercent($device->latestMetric->cpu),
                ram: $device->latestMetric->ramRoundedForHumans(),
                ramLevel: LoadLevel::fromPercent($device->latestMetric->ram),
            ))->values(),
        ]);
    }
}
