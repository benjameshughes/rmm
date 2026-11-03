<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\Dashboard\AttentionChip;
use App\DTOs\DeviceAttention;
use App\DTOs\Printers\PrinterAttention;
use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything wrong with one device as a row of small chips. An open alert that repeats
 * a problem already shown as its own chip (a full disk, an old agent) is left out.
 */
final class BuildAttentionChips
{
    /**
     * @return Collection<int, AttentionChip>
     */
    public function __invoke(DeviceAttention $attention, ?string $latestAgentVersion): Collection
    {
        $device = $attention->device;

        return collect()
            ->when($attention->isOfflineUnexpectedly, fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: 'quiet '.($device->last_seen?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true) ?? ''),
                color: 'red',
                detail: 'Went quiet without announcing a shutdown · '.$device->lastSeenForHumans(),
            )))
            ->merge($attention->disks->map(fn (array $disk): AttentionChip => new AttentionChip(
                label: "{$disk['name']} {$disk['usedRoundedForHumans']}",
                color: $disk['usedPercent'] >= config('devices.disk.critical_percent') ? 'red' : 'amber',
                detail: $disk['freeForHumans'],
            )))
            ->merge($attention->inodeDisks->map(fn (array $disk): AttentionChip => new AttentionChip(
                label: "{$disk['name']} inodes ".number_format($disk['inodePercent']).'%',
                color: $disk['inodePercent'] >= config('devices.disk.inode_critical_percent') ? 'red' : 'amber',
                detail: $disk['inodeForHumans'],
            )))
            ->when($attention->failedUnits->isNotEmpty(), fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: $attention->failedUnits->count().' '.Str::plural('unit', $attention->failedUnits->count()),
                color: 'red',
                detail: $attention->failedUnits->implode(', ').' failed',
            )))
            ->when($attention->printerProblems->isNotEmpty(), fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: $attention->printerProblems->count().' '.Str::plural('printer', $attention->printerProblems->count()),
                color: 'red',
                detail: $attention->printerProblems->map(fn (PrinterAttention $problem): string => trim(($problem->printerName ?? '').': '.$problem->problem, ': '))->implode("\n"),
            )))
            ->when($attention->isVirtualPrinterDown, fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: 'printer down',
                color: 'amber',
                detail: config('devices.watched_apps.virtual_printer.label').' · '.$device->virtualPrinterLabel(),
            )))
            ->when($attention->hasBackupProblem(), fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: 'backup '.Str::lower($attention->backupState->label()),
                color: $attention->backupState->color(),
                detail: $device->backupProblem(),
            )))
            ->merge($this->alertChips($attention))
            ->when($attention->isAgentBehind, fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: "agent {$device->agent_version} → {$latestAgentVersion}",
                color: 'amber',
                detail: 'Update available',
            )))
            ->when($attention->isRebootRequired, fn (Collection $chips): Collection => $chips->push(new AttentionChip(
                label: 'reboot',
                color: 'sky',
                detail: 'Reboot required',
            )))
            ->values();
    }

    /**
     * Open alerts grouped by what they watch, one chip per kind, coloured by the worst of them.
     *
     * @return Collection<int, AttentionChip>
     */
    private function alertChips(DeviceAttention $attention): Collection
    {
        $shownElsewhere = collect([
            AlertMetric::Disk->value => $attention->disks->isNotEmpty(),
            AlertMetric::Offline->value => $attention->isOfflineUnexpectedly,
            AlertMetric::AgentOutdated->value => $attention->isAgentBehind,
            AlertMetric::PrinterProblem->value => $attention->printerProblems->isNotEmpty(),
            AlertMetric::SpoolerDown->value => $attention->printerProblems->isNotEmpty(),
            AlertMetric::BackupOverdue->value => $attention->hasBackupProblem(),
        ]);

        return $attention->alerts
            ->reject(fn (Alert $alert): bool => $shownElsewhere->get($alert->metric->value, false))
            ->groupBy(fn (Alert $alert): string => $alert->metric->value)
            ->map(fn (Collection $alerts): AttentionChip => new AttentionChip(
                label: ($alerts->count() > 1 ? $alerts->count().' ' : '').Str::plural($alerts->first()->metric->chipLabel(), $alerts->count()),
                color: $alerts->contains(fn (Alert $alert): bool => $alert->severity === AlertSeverity::Critical) ? AlertSeverity::Critical->color() : AlertSeverity::Warning->color(),
                detail: $alerts->map(fn (Alert $alert): string => "{$alert->valueLabel()} · since {$alert->triggered_at->diffForHumans()}")->implode("\n"),
            ))
            ->values();
    }
}
