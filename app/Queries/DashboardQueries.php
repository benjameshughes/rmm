<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Dashboard\Ring;
use App\DTOs\Dashboard\RingSegment;
use App\DTOs\DeviceAttention;
use App\DTOs\Printers\PrinterAttention;
use App\Enums\ChartInk;
use App\Enums\DeviceStatus;
use App\Enums\VirtualPrinterState;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DevicePrinter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The dashboard reads the approved fleet once, with each device's latest report and its disks,
 * and works every device section out from that one load.
 */
final class DashboardQueries
{
    public function __construct(private readonly DeviceListQueries $deviceList) {}

    /** @return EloquentCollection<int, Device> */
    public function fleet(): EloquentCollection
    {
        return Device::query()
            ->where('status', DeviceStatus::Active)
            ->with('latestMetric.diskMetrics')
            ->orderBy('hostname')
            ->get();
    }

    /**
     * @return array{total: int, online: int, poweringOff: int, offline: int, outdated: int, openAlerts: int, managed: int, monitorOnly: int}
     */
    public function summary(): array
    {
        return [
            ...$this->deviceList->summary(),
            'managed' => Device::query()->where('status', DeviceStatus::Active)->acceptsCommands()->count(),
            'monitorOnly' => Device::query()->where('status', DeviceStatus::Active)->where('is_monitor_only', true)->count(),
        ];
    }

    /**
     * Devices with a disk or inodes at or over the warning threshold, an open alert, a failed
     * service, a silence they never announced, an old agent or a pending reboot, worst first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, DeviceAttention>
     */
    public function needsAttention(EloquentCollection $fleet, ?string $latestAgentVersion): Collection
    {
        $openAlerts = Alert::query()
            ->unresolved()
            ->whereIn('device_id', $fleet->modelKeys())
            ->with('alertRule')
            ->latest('triggered_at')
            ->get()
            ->groupBy('device_id');

        $printerProblems = $this->printerProblems($fleet)->groupBy(fn (PrinterAttention $attention): int => $attention->device->id);

        return $fleet
            ->map(fn (Device $device): DeviceAttention => $this->attentionFor($device, $openAlerts->get($device->id, collect()), $latestAgentVersion, $printerProblems->get($device->id, collect())))
            ->filter(fn (DeviceAttention $attention): bool => $attention->needsAttention())
            ->sort(fn (DeviceAttention $first, DeviceAttention $second): int => $first->sortKey() <=> $second->sortKey())
            ->values();
    }

    /**
     * @param  Collection<int, Alert>  $openAlerts
     * @param  Collection<int, PrinterAttention>  $printerProblems
     */
    private function attentionFor(Device $device, Collection $openAlerts, ?string $latestAgentVersion, Collection $printerProblems): DeviceAttention
    {
        $disks = $device->diskUsage();

        return new DeviceAttention(
            device: $device,
            disks: $disks
                ->filter(fn (array $disk): bool => $disk['usedPercent'] !== null && $disk['usedPercent'] >= config('devices.disk.warning_percent'))
                ->sortByDesc('usedPercent')
                ->values(),
            alerts: $openAlerts,
            isOfflineUnexpectedly: ! $device->isOnline && ! $device->isPoweringOff,
            isAgentBehind: $device->isAgentOutdated($latestAgentVersion),
            failedUnits: collect($device->latestMetric?->failed_units ?? []),
            inodeDisks: $disks
                ->filter(fn (array $disk): bool => $disk['inodePercent'] !== null && $disk['inodePercent'] >= config('devices.disk.inode_warning_percent'))
                ->sortByDesc('inodePercent')
                ->values(),
            isRebootRequired: (bool) $device->latestMetric?->reboot_required,
            printerProblems: $printerProblems->values(),
            backupState: $device->backupState(),
            isVirtualPrinterDown: $device->virtualPrinterState() === VirtualPrinterState::Down,
        );
    }

    /**
     * Printing problems on plainly online PCs, worst first: stopped spoolers,
     * printers Windows flags as broken, failing jobs, then backed-up queues,
     * each longest-running first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, PrinterAttention>
     */
    public function printerProblems(EloquentCollection $fleet): Collection
    {
        $problemPrinters = DevicePrinter::query()
            ->whereNotNull('problem_since')
            ->whereIn('device_id', $fleet->modelKeys())
            ->get()
            ->groupBy('device_id');

        return $fleet->toBase()
            ->each(fn (Device $device): Device => $device->setRelation('problemPrinters', new EloquentCollection($problemPrinters->get($device->id, []))))
            ->flatMap($this->printerAttentionFor(...))
            ->sortBy(fn (PrinterAttention $attention): array => [$attention->rank, $attention->since->getTimestamp()])
            ->values();
    }

    /**
     * @return Collection<int, PrinterAttention>
     */
    private function printerAttentionFor(Device $device): Collection
    {
        return $device->currentPrinterProblems()
            ->toBase()
            ->map(fn (DevicePrinter $printer): PrinterAttention => new PrinterAttention(
                device: $device,
                printerName: $printer->name,
                problem: $printer->queue()->problemSummary(),
                since: $printer->problem_since,
                rank: $printer->queue()->problemRank(),
            ))
            ->when($device->isSpoolerDown, fn (Collection $attentions): Collection => $attentions->prepend(new PrinterAttention(
                device: $device,
                printerName: null,
                problem: 'Print spooler not running',
                since: $device->spooler_down_since,
                rank: -1,
            )));
    }

    /**
     * Each device's fullest disk, fullest first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, array{device: Device, disk: array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}}>
     */
    public function fullestDisks(EloquentCollection $fleet, int $limit): Collection
    {
        return $fleet
            ->map(fn (Device $device): array => ['device' => $device, 'disk' => $device->fullestDisk()])
            ->map(fn (array $row): array => [...$row, 'meterColor' => ($row['disk']['usedPercent'] ?? 0) >= config('devices.disk.warning_percent') ? $row['disk']['barColor'] : 'zinc'])
            ->filter(fn (array $row): bool => ($row['disk']['usedPercent'] ?? null) !== null)
            ->sortByDesc(fn (array $row): float => $row['disk']['usedPercent'])
            ->take($limit)
            ->values();
    }

    /**
     * Online devices working hardest right now, by whichever of CPU and RAM is higher.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, Device>
     */
    public function busiest(EloquentCollection $fleet, int $limit): Collection
    {
        return $fleet
            ->filter(fn (Device $device): bool => $device->isOnline && $device->latestMetric !== null)
            ->sortByDesc(fn (Device $device): float => max($device->latestMetric->cpu ?? 0, $device->latestMetric->ram ?? 0))
            ->take($limit)
            ->values();
    }

    /**
     * The agent ring: devices on the latest agent, behind it, and not reporting a version.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     */
    public function agentRing(EloquentCollection $fleet, ?string $latestAgentVersion): Ring
    {
        $unknown = $fleet->filter(fn (Device $device): bool => $device->agent_version === null)->count();
        $behind = $fleet->filter(fn (Device $device): bool => $device->isAgentOutdated($latestAgentVersion))->count();

        return new Ring(collect([
            new RingSegment(label: 'Current', value: $fleet->count() - $unknown - $behind, ink: ChartInk::Ink),
            new RingSegment(label: 'Behind', value: $behind, ink: ChartInk::Warning),
            new RingSegment(label: 'Unknown', value: $unknown, ink: ChartInk::Faint),
        ]));
    }

    /**
     * The disks ring: every device's fullest disk counted into bands. Bands under the warning
     * line are greys; at or over warning is amber, at or over critical is red.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     */
    public function diskRing(EloquentCollection $fleet): Ring
    {
        $warning = config('devices.disk.warning_percent');
        $critical = config('devices.disk.critical_percent');
        $edges = collect([0, ...config('dashboard.disk_bands'), $warning, $critical, PHP_INT_MAX]);
        $calmBands = ChartInk::calmBands();
        $percents = $fleet->toBase()
            ->map(fn (Device $device): ?float => $device->fullestDisk()['usedPercent'] ?? null)
            ->filter(fn (?float $percent): bool => $percent !== null);

        return new Ring($edges->sliding(2)->values()->map(fn (Collection $pair, int $index): RingSegment => new RingSegment(
            label: $pair->last() === PHP_INT_MAX ? "{$pair->first()}%+" : "{$pair->first()}–{$pair->last()}%",
            value: $percents->filter(fn (float $percent): bool => $percent >= $pair->first() && $percent < $pair->last())->count(),
            ink: match (true) {
                $pair->first() >= $critical => ChartInk::Critical,
                $pair->first() >= $warning => ChartInk::Warning,
                default => $calmBands[min($index, count($calmBands) - 1)],
            },
        )));
    }

    /**
     * Devices that went quiet without a shutdown, most recently seen first, for the Offline hero tile.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, Device>
     */
    public function offlineDevices(EloquentCollection $fleet): Collection
    {
        return $fleet->toBase()
            ->filter(fn (Device $device): bool => ! $device->isOnline && ! $device->isPoweringOff)
            ->sortByDesc(fn (Device $device): int => $device->last_seen?->getTimestamp() ?? 0)
            ->values();
    }
}
