<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\DeviceAttention;
use App\Enums\ChartInk;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The segmented meters under the Disks and Failed units hero figures: one segment per device,
 * problems first, capped at dashboard.hero_segments. Each segment is its ink.
 */
final class BuildHeroMeters
{
    /**
     * @param  EloquentCollection<int, Device>  $fleet
     * @param  Collection<int, DeviceAttention>  $attention
     * @return array{disks: Collection<int, ChartInk>, units: Collection<int, ChartInk>}
     */
    public function __invoke(EloquentCollection $fleet, Collection $attention): array
    {
        $byDevice = $attention->keyBy(fn (DeviceAttention $item): int => $item->device->id);

        return [
            'disks' => $this->meter($fleet->toBase()
                ->filter(fn (Device $device): bool => $device->fullestDisk() !== null)
                ->map(fn (Device $device): ChartInk => $this->diskInk($byDevice->get($device->id)))),
            'units' => $this->meter($fleet->toBase()
                ->filter(fn (Device $device): bool => $device->platform() === ScriptPlatform::Linux)
                ->map(fn (Device $device): ChartInk => ($byDevice->get($device->id)?->failedUnits->isNotEmpty() ?? false) ? ChartInk::Critical : ChartInk::Faint)),
        ];
    }

    /**
     * @param  Collection<int, ChartInk>  $inks
     * @return Collection<int, ChartInk>
     */
    private function meter(Collection $inks): Collection
    {
        return $inks
            ->sortBy(fn (ChartInk $ink): int => $ink->isProblem() ? 0 : 1)
            ->take(config('dashboard.hero_segments'))
            ->values();
    }

    private function diskInk(?DeviceAttention $attention): ChartInk
    {
        return match (true) {
            $attention === null || $attention->disks->isEmpty() => ChartInk::Faint,
            $attention->worstDiskPercent() >= config('devices.disk.critical_percent') => ChartInk::Critical,
            default => ChartInk::Warning,
        };
    }
}
