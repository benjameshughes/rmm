<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * Memory, storage, displays, printers, adapters, peripherals and battery from one system inventory.
 */
final class InventoryHardware
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data  The whole inventory, since hardware spans several top-level keys
     */
    public function __construct(
        private readonly array $data,
    ) {}

    public function processorName(): ?string
    {
        return $this->textAt('cpu.name');
    }

    /**
     * The physical disks' combined size and media, such as "477 GB SSD".
     */
    public function diskSummary(): ?string
    {
        $disks = $this->rows('disks');
        $sizes = $disks->map(fn (array $disk): mixed => $disk['size_gb'] ?? null)->filter(fn (mixed $size): bool => is_numeric($size));
        $mediaTypes = $disks
            ->map(fn (array $disk): ?string => $this->text($disk['media_type'] ?? null))
            ->filter(fn (?string $mediaType): bool => $mediaType !== null && $mediaType !== 'Unspecified')
            ->unique()
            ->implode('/');

        return $sizes->isEmpty() ? null : $this->joined(' ', number_format((float) $sizes->sum()).' GB', $mediaTypes === '' ? null : $mediaTypes);
    }

    /**
     * How many monitors and the models that report a name, such as "2 · DELL P2422H".
     */
    public function monitorSummary(): ?string
    {
        $monitors = $this->rows('monitors');
        $names = $monitors
            ->map(fn (array $monitor): ?string => $this->text($monitor['name'] ?? null))
            ->filter()
            ->unique()
            ->implode(', ');

        return $monitors->isEmpty() ? null : $this->joined(' · ', (string) $monitors->count(), $names === '' ? null : $names);
    }

    public function memorySummary(): ?string
    {
        $slotsTotal = $this->textAt('memory.slots_total');
        $slotsUsed = $this->rows('memory.modules')->count();

        return $this->joined(' · ', $this->gigabytes(data_get($this->data, 'memory.total_gb')), $slotsTotal === null ? null : "{$slotsUsed} of {$slotsTotal} slots used");
    }

    public function memoryModules(): InventoryTable
    {
        return $this->table('memory.modules', [
            'Slot' => fn (array $module): ?string => $this->joined(' · ', $this->text($module['slot'] ?? null), $this->text($module['bank'] ?? null)),
            'Size' => fn (array $module): ?string => $this->gigabytes($module['capacity_gb'] ?? null),
            'Speed' => fn (array $module): ?string => is_numeric($module['speed_mhz'] ?? null) && $module['speed_mhz'] > 0 ? "{$module['speed_mhz']} MHz" : null,
            'Manufacturer' => fn (array $module): ?string => $this->text($module['manufacturer'] ?? null),
            'Part number' => fn (array $module): ?string => $this->text($module['part_number'] ?? null),
            'Serial' => fn (array $module): ?string => $this->text($module['serial'] ?? null),
        ]);
    }

    public function disks(): InventoryTable
    {
        return $this->table('disks', [
            'Model' => fn (array $disk): ?string => $this->text($disk['model'] ?? null),
            'Size' => fn (array $disk): ?string => $this->gigabytes($disk['size_gb'] ?? null),
            'Type' => fn (array $disk): ?string => $this->joined(' · ', $this->text($disk['media_type'] ?? null), $this->text($disk['bus_type'] ?? null)),
            'Health' => fn (array $disk): ?string => $this->joined(' · ', $this->text($disk['health'] ?? null), $this->text($disk['operational_status'] ?? null)),
            'Serial' => fn (array $disk): ?string => $this->text($disk['serial'] ?? null),
        ]);
    }

    public function volumes(): InventoryTable
    {
        return $this->table('volumes', [
            'Drive' => fn (array $volume): ?string => $this->text($volume['drive'] ?? null),
            'Label' => fn (array $volume): ?string => $this->text($volume['label'] ?? null),
            'File system' => fn (array $volume): ?string => $this->text($volume['file_system'] ?? null),
            'Size' => fn (array $volume): ?string => $this->gigabytes($volume['size_gb'] ?? null),
            'Free' => fn (array $volume): ?string => $this->gigabytes($volume['free_gb'] ?? null),
        ]);
    }

    public function gpus(): InventoryTable
    {
        return $this->table('gpus', [
            'Name' => fn (array $gpu): ?string => $this->text($gpu['name'] ?? null),
            'Driver' => fn (array $gpu): ?string => $this->joined(' · ', $this->text($gpu['driver_version'] ?? null), $this->date($gpu['driver_date'] ?? null)),
            'Resolution' => fn (array $gpu): ?string => $this->text($gpu['resolution'] ?? null),
        ]);
    }

    public function monitors(): InventoryTable
    {
        return $this->table('monitors', [
            'Name' => fn (array $monitor): ?string => $this->text($monitor['name'] ?? null) ?? $this->text($monitor['product_code'] ?? null),
            'Manufacturer' => fn (array $monitor): ?string => $this->text($monitor['manufacturer'] ?? null),
            'Serial' => fn (array $monitor): ?string => $this->text($monitor['serial'] ?? null),
            'Made' => fn (array $monitor): ?string => $this->joined(', ', isset($monitor['week']) && $monitor['week'] > 0 ? "Week {$monitor['week']}" : null, $this->text($monitor['year'] ?? null)),
        ]);
    }

    public function printers(): InventoryTable
    {
        return $this->table('printers', [
            'Name' => fn (array $printer): ?string => $this->text($printer['name'] ?? null),
            'Driver' => fn (array $printer): ?string => $this->text($printer['driver'] ?? null),
            'Port' => fn (array $printer): ?string => $this->text($printer['port'] ?? null),
            'Default' => fn (array $printer): ?string => $this->yesNo($printer['is_default'] ?? null),
            'Shared' => fn (array $printer): ?string => $this->yesNo($printer['is_shared'] ?? null),
            'Network' => fn (array $printer): ?string => $this->yesNo($printer['is_network'] ?? null),
        ]);
    }

    public function networkAdapters(): InventoryTable
    {
        return $this->table('network_adapters', [
            'Name' => fn (array $adapter): ?string => $this->text($adapter['name'] ?? null),
            'Description' => fn (array $adapter): ?string => $this->text($adapter['description'] ?? null),
            'MAC' => fn (array $adapter): ?string => $this->text($adapter['mac_address'] ?? null),
            'Link' => fn (array $adapter): ?string => $this->joined(' · ', $this->text($adapter['status'] ?? null), $this->text($adapter['link_speed'] ?? null)),
            'Driver' => fn (array $adapter): ?string => $this->joined(' · ', $this->text($adapter['driver_provider'] ?? null), $this->text($adapter['driver_version'] ?? null), $this->text($adapter['driver_date'] ?? null)),
        ]);
    }

    public function usbDevices(): InventoryTable
    {
        return $this->table('usb_devices', [
            'Name' => fn (array $device): ?string => $this->text($device['name'] ?? null),
            'Manufacturer' => fn (array $device): ?string => $this->text($device['manufacturer'] ?? null),
            'Class' => fn (array $device): ?string => $this->text($device['class'] ?? null),
            'Status' => fn (array $device): ?string => $this->text($device['status'] ?? null),
        ]);
    }

    public function inputDevices(): InventoryTable
    {
        $devices = $this->rows('input_devices.keyboards')
            ->map(fn (array $device): array => [...$device, 'type' => 'Keyboard'])
            ->concat($this->rows('input_devices.pointing_devices')->map(fn (array $device): array => [...$device, 'type' => 'Pointing device']));

        return $this->tableFrom($devices, [
            'Type' => fn (array $device): ?string => $device['type'],
            'Name' => fn (array $device): ?string => $this->text($device['name'] ?? null),
            'Manufacturer' => fn (array $device): ?string => $this->text($device['manufacturer'] ?? null),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function battery(): array
    {
        $designCapacity = data_get($this->data, 'battery.design_capacity_mwh');
        $fullChargeCapacity = data_get($this->data, 'battery.full_charge_capacity_mwh');
        $capacities = is_numeric($designCapacity) && is_numeric($fullChargeCapacity)
            ? number_format((float) $fullChargeCapacity).' of '.number_format((float) $designCapacity).' mWh'
            : null;
        $charge = $this->textAt('battery.charge_percent');
        $health = $this->textAt('battery.health_percent');

        return $this->facts([
            'Battery' => $this->textAt('battery.name'),
            'Charge' => $charge === null ? null : "{$charge}%",
            'Health' => $this->joined(' · ', $health === null ? null : "{$health}% of design capacity", $capacities),
        ]);
    }
}
