<?php

declare(strict_types=1);

namespace App\DTOs\Software;

use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceSoftware;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One package action across packages and devices, worked out before it is
 * confirmed so the modal can say exactly what will be queued.
 */
final readonly class PackageCommandPlan
{
    /**
     * @param  Collection<string, Collection<int, Device>>  $devicesByPackage  Keyed by package ID
     * @param  int  $skippedCount  Outdated installs winget cannot upgrade by ID, or devices that already have the package
     */
    public function __construct(
        public PackageAction $action,
        public Collection $devicesByPackage,
        public int $skippedCount = 0,
    ) {}

    /**
     * An upgrade only reaches installs with an update winget can apply; an
     * uninstall reaches every install.
     *
     * @param  Collection<int, DeviceSoftware>  $installs  With their device loaded
     */
    public static function forInstalls(PackageAction $action, Collection $installs): self
    {
        $changeable = $action === PackageAction::Upgrade
            ? $installs->filter(fn (DeviceSoftware $install): bool => $install->isUpgradable)
            : $installs;

        $skipped = $action === PackageAction::Upgrade
            ? $installs->filter(fn (DeviceSoftware $install): bool => $install->is_update_available)->count() - $changeable->count()
            : 0;

        return new self(
            action: $action,
            devicesByPackage: $changeable
                ->groupBy('package_id')
                ->map(fn (Collection $packageInstalls): Collection => $packageInstalls->map(fn (DeviceSoftware $install): Device => $install->device)->values()),
            skippedCount: $skipped,
        );
    }

    public function commandCount(): int
    {
        return $this->devicesByPackage->sum(fn (Collection $devices): int => $devices->count());
    }

    /** @return Collection<int, Device> */
    public function devices(): Collection
    {
        return $this->devicesByPackage->flatten(1)->unique('id')->values();
    }

    public function deviceCount(): int
    {
        return $this->devices()->count();
    }

    public function isEmpty(): bool
    {
        return $this->commandCount() === 0;
    }

    /**
     * E.g. "Upgrade 3 apps: 14 commands across 9 devices."
     */
    public function summary(): string
    {
        if ($this->isEmpty()) {
            return "Nothing to {$this->action->verb()}.";
        }

        $packages = $this->devicesByPackage->count() === 1
            ? $this->devicesByPackage->keys()->first()
            : $this->devicesByPackage->count().' apps';
        $commands = $this->commandCount().' '.Str::plural('command', $this->commandCount());
        $devices = $this->deviceCount().' '.Str::plural('device', $this->deviceCount());

        return "{$this->action->label()} {$packages}: {$commands} across {$devices}.";
    }

    public function skippedNote(): ?string
    {
        if ($this->skippedCount === 0) {
            return null;
        }

        return match ($this->action) {
            PackageAction::Upgrade => $this->skippedCount.' outdated '.Str::plural('install', $this->skippedCount).' skipped: winget did not install '.($this->skippedCount === 1 ? 'it' : 'them').', so it cannot upgrade '.($this->skippedCount === 1 ? 'it' : 'them').' by ID.',
            PackageAction::Install => $this->skippedCount.' '.Str::plural('device', $this->skippedCount).' skipped: '.($this->skippedCount === 1 ? 'it already has' : 'they already have').' it.',
            PackageAction::Uninstall => null,
        };
    }
}
