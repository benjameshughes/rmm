<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\DTOs\DeviceAttention;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The segmented meters under the Disks and Failed units hero figures: one segment per device,
 * problems first, capped at dashboard.hero_segments. Each segment is just its colour class.
 */
final class BuildHeroMeters
{
    /**
     * @param  EloquentCollection<int, Device>  $fleet
     * @param  Collection<int, DeviceAttention>  $attention
     * @return array{disks: Collection<int, string>, units: Collection<int, string>}
     */
    public function __invoke(EloquentCollection $fleet, Collection $attention): array
    {
        $byDevice = $attention->keyBy(fn (DeviceAttention $item): int => $item->device->id);

        return [
            'disks' => $this->meter($fleet->toBase()
                ->filter(fn (Device $device): bool => $device->fullestDisk() !== null)
                ->map(fn (Device $device): string => $this->diskClass($byDevice->get($device->id)))),
            'units' => $this->meter($fleet->toBase()
                ->filter(fn (Device $device): bool => $device->platform() === ScriptPlatform::Linux)
                ->map(fn (Device $device): string => ($byDevice->get($device->id)?->failedUnits->isNotEmpty() ?? false) ? 'bg-red-500' : $this->calm())),
        ];
    }

    /**
     * @param  Collection<int, string>  $classes
     * @return Collection<int, string>
     */
    private function meter(Collection $classes): Collection
    {
        return $classes
            ->sortBy(fn (string $class): int => $class === $this->calm() ? 1 : 0)
            ->take(config('dashboard.hero_segments'))
            ->values();
    }

    private function diskClass(?DeviceAttention $attention): string
    {
        return match (true) {
            $attention === null || $attention->disks->isEmpty() => $this->calm(),
            $attention->worstDiskPercent() >= config('devices.disk.critical_percent') => 'bg-red-500',
            default => 'bg-amber-500',
        };
    }

    private function calm(): string
    {
        return 'bg-zinc-200 dark:bg-zinc-700';
    }
}
