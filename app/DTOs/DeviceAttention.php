<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AlertSeverity;
use App\Enums\AttentionLevel;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * Everything wrong with one device, for the dashboard's "Needs attention" list.
 */
final class DeviceAttention
{
    /**
     * @param  Collection<int, array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}>  $disks  Disks at or over the warning threshold, fullest first
     * @param  Collection<int, Alert>  $alerts  Open alerts, newest first
     * @param  Collection<int, string>  $failedUnits  systemd units in a failed state
     * @param  Collection<int, array{name: string, mountPoint: ?string, availableGb: ?float, totalGb: ?float, usedPercent: ?float, usedForHumans: ?string, freeForHumans: ?string, barColor: string, usedRoundedForHumans: ?string, usedTextColor: string, inodePercent: ?float, inodeForHumans: ?string, inodeColor: string}>  $inodeDisks  Disks with inode usage at or over the inode warning threshold, fullest first
     */
    public function __construct(
        public readonly Device $device,
        public readonly Collection $disks,
        public readonly Collection $alerts,
        public readonly bool $isOfflineUnexpectedly,
        public readonly bool $isAgentBehind,
        public readonly Collection $failedUnits = new Collection,
        public readonly Collection $inodeDisks = new Collection,
        public readonly bool $isRebootRequired = false,
    ) {}

    public function needsAttention(): bool
    {
        return $this->disks->isNotEmpty()
            || $this->alerts->isNotEmpty()
            || $this->isOfflineUnexpectedly
            || $this->isAgentBehind
            || $this->failedUnits->isNotEmpty()
            || $this->inodeDisks->isNotEmpty()
            || $this->isRebootRequired;
    }

    /**
     * A disk or inodes past the critical threshold, a failed service or a critical alert make the
     * device critical; a reboot on its own is only worth knowing.
     */
    public function severity(): AttentionLevel
    {
        $isCritical = $this->worstDiskPercent() >= config('devices.disk.critical_percent')
            || $this->worstInodePercent() >= config('devices.disk.inode_critical_percent')
            || $this->failedUnits->isNotEmpty()
            || $this->alerts->contains(fn (Alert $alert): bool => $alert->severity === AlertSeverity::Critical);

        $isWarning = $this->disks->isNotEmpty()
            || $this->inodeDisks->isNotEmpty()
            || $this->alerts->isNotEmpty()
            || $this->isOfflineUnexpectedly
            || $this->isAgentBehind;

        return match (true) {
            $isCritical => AttentionLevel::Critical,
            $isWarning => AttentionLevel::Warning,
            default => AttentionLevel::Info,
        };
    }

    public function worstDiskPercent(): float
    {
        return (float) ($this->disks->max('usedPercent') ?? 0);
    }

    public function worstInodePercent(): float
    {
        return (float) ($this->inodeDisks->max('inodePercent') ?? 0);
    }

    /**
     * Critical first, then the fullest disk, then by name so the order never shuffles between redraws.
     *
     * @return array{0: int, 1: float, 2: string}
     */
    public function sortKey(): array
    {
        return [$this->severity()->rank(), -max($this->worstDiskPercent(), $this->worstInodePercent()), $this->device->hostname];
    }
}
