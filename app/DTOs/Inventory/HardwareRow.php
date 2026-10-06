<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use App\Models\Device;
use Illuminate\Support\Str;

/**
 * One device's line on the fleet hardware page, read from its latest system inventory.
 */
final readonly class HardwareRow
{
    public function __construct(
        public Device $device,
        public ?string $model,
        public ?string $serviceTag,
        public ?string $processor,
        public ?string $ram,
        public ?string $disk,
        public ?string $windowsEdition,
        public ?string $windowsBuild,
        public ?string $monitors,
        public string $collectedForHumans,
        public string $collectedAt,
    ) {}

    public static function for(Device $device): self
    {
        $inventory = $device->latestInventory;
        $hardware = $inventory->snapshot()->hardware();
        $model = trim("{$inventory->manufacturer} {$inventory->model}");

        return new self(
            device: $device,
            model: $model === '' ? null : $model,
            serviceTag: $inventory->serial_number,
            processor: $hardware->processorName(),
            ram: $inventory->total_ram_gb === null ? null : ((float) $inventory->total_ram_gb).' GB',
            disk: $hardware->diskSummary(),
            windowsEdition: $inventory->windows_edition === null ? null : Str::chopStart($inventory->windows_edition, 'Microsoft '),
            windowsBuild: $inventory->windows_build,
            monitors: $hardware->monitorSummary(),
            collectedForHumans: $inventory->collected_at->diffForHumans(),
            collectedAt: $inventory->collected_at->inDisplayTimezone()->format('j M Y, H:i'),
        );
    }
}
