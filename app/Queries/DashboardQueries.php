<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\DeviceAttention;
use App\DTOs\Printers\PrinterAttention;
use App\Enums\BackupState;
use App\Enums\DeviceStatus;
use App\Enums\VirtualPrinterState;
use App\Models\Alert;
use App\Models\AuditLog;
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

        return $fleet
            ->map(fn (Device $device): DeviceAttention => $this->attentionFor($device, $openAlerts->get($device->id, collect()), $latestAgentVersion))
            ->filter(fn (DeviceAttention $attention): bool => $attention->needsAttention())
            ->sort(fn (DeviceAttention $first, DeviceAttention $second): int => $first->sortKey() <=> $second->sortKey())
            ->values();
    }

    /**
     * @param  Collection<int, Alert>  $openAlerts
     */
    private function attentionFor(Device $device, Collection $openAlerts, ?string $latestAgentVersion): DeviceAttention
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
        );
    }

    /**
     * Plainly online print stations and whether each can print labels, down ones first.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, array{device: Device, state: VirtualPrinterState}>
     */
    public function printStations(EloquentCollection $fleet): Collection
    {
        return $fleet
            ->map(fn (Device $device): array => ['device' => $device, 'state' => $device->virtualPrinterState()])
            ->reject(fn (array $station): bool => $station['state'] === VirtualPrinterState::Unwatched)
            ->sortBy(fn (array $station): int => $station['state'] === VirtualPrinterState::Down ? 0 : 1)
            ->values();
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
     * PCs with backup credentials whose last run failed or whose last good
     * backup is overdue, failed first, then longest overdue.
     *
     * @param  EloquentCollection<int, Device>  $fleet
     * @return Collection<int, array{device: Device, state: BackupState, problem: string}>
     */
    public function backupProblems(EloquentCollection $fleet): Collection
    {
        return $fleet->toBase()
            ->map(fn (Device $device): array => ['device' => $device, 'state' => $device->backupState(), 'problem' => $device->backupProblem()])
            ->filter(fn (array $row): bool => $row['state']->needsAttention())
            ->sortBy(fn (array $row): array => [$row['state'] === BackupState::Failed ? 0 : 1, $row['device']->last_good_backup_at?->getTimestamp() ?? 0])
            ->values();
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

    /** @return EloquentCollection<int, Alert> */
    public function recentAlerts(int $limit): EloquentCollection
    {
        return Alert::query()->with(['device', 'alertRule'])->latest('triggered_at')->latest('id')->limit($limit)->get();
    }

    /** @return EloquentCollection<int, AuditLog> */
    public function recentActivity(int $limit): EloquentCollection
    {
        return AuditLog::query()->with('user')->latest('id')->limit($limit)->get();
    }
}
