<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * Everything wrong with one device, for the dashboard's "Needs attention" list.
 */
final class DeviceAttention
{
    /**
     * @param  Collection<int, array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string}>  $disks  Disks at or over the warning threshold, fullest first
     * @param  Collection<int, Alert>  $alerts  Open alerts, newest first
     */
    public function __construct(
        public readonly Device $device,
        public readonly Collection $disks,
        public readonly Collection $alerts,
        public readonly bool $isOfflineUnexpectedly,
        public readonly bool $isAgentBehind,
    ) {}

    public function needsAttention(): bool
    {
        return $this->disks->isNotEmpty()
            || $this->alerts->isNotEmpty()
            || $this->isOfflineUnexpectedly
            || $this->isAgentBehind;
    }

    /**
     * A disk past the critical threshold or a critical alert makes the whole device critical.
     */
    public function severity(): AlertSeverity
    {
        $isCritical = $this->worstDiskPercent() >= config('devices.disk.critical_percent')
            || $this->alerts->contains(fn (Alert $alert): bool => $alert->severity === AlertSeverity::Critical);

        return $isCritical ? AlertSeverity::Critical : AlertSeverity::Warning;
    }

    public function severityIconColor(): string
    {
        return $this->severity() === AlertSeverity::Critical ? 'text-red-500' : 'text-amber-500';
    }

    public function worstDiskPercent(): float
    {
        return (float) ($this->disks->max('usedPercent') ?? 0);
    }

    /**
     * Critical first, then the fullest disk, then by name so the order never shuffles between redraws.
     *
     * @return array{0: int, 1: float, 2: string}
     */
    public function sortKey(): array
    {
        return [$this->severity() === AlertSeverity::Critical ? 0 : 1, -$this->worstDiskPercent(), $this->device->hostname];
    }
}
