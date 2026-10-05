<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Illuminate\Support\Str;

/**
 * The decoded output of one system-inventory run, with typed reads for the
 * device's System tab and the values promoted to columns.
 */
final class SystemInventory
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly array $data,
    ) {}

    public function security(): InventorySecurity
    {
        return new InventorySecurity($this->section('security'));
    }

    public function users(): InventoryUsers
    {
        return new InventoryUsers($this->section('users'));
    }

    public function hardware(): InventoryHardware
    {
        return new InventoryHardware($this->data);
    }

    public function software(): InventorySoftware
    {
        return new InventorySoftware($this->data);
    }

    /**
     * @return array<string, string>
     */
    public function overview(): array
    {
        return $this->facts([
            'Manufacturer' => $this->textAt('system.manufacturer'),
            'Model' => $this->joined(' · ', $this->textAt('system.model'), $this->textAt('system.chassis_type')),
            'Service tag' => $this->textAt('system.serial_number'),
            'BIOS' => $this->joined(' · ', $this->textAt('system.bios.version'), $this->date(data_get($this->data, 'system.bios.release_date'))),
            'CPU' => $this->cpu(),
            'RAM' => $this->gigabytes(data_get($this->data, 'memory.total_gb')),
            'Windows' => $this->joined(' ', $this->textAt('windows.caption'), $this->textAt('windows.display_version')),
            'Build' => $this->windowsBuild(),
            'Installed' => $this->date(data_get($this->data, 'windows.install_date')),
            'Activation' => $this->joined(' · ', $this->textAt('windows.activation.license_status'), $this->textAt('windows.activation.channel')),
            'Domain' => $this->domain(),
            'Last boot' => $this->lastBoot(),
            'Time zone' => $this->textAt('windows.timezone'),
            'Time source' => $this->textAt('windows.time_source'),
        ]);
    }

    /**
     * The values stored in their own columns so the fleet can be queried and sorted on them.
     *
     * @return array{manufacturer: ?string, model: ?string, serial_number: ?string, total_ram_gb: ?float, windows_edition: ?string, windows_build: ?string, is_bitlocker_on: ?bool, is_secure_boot: ?bool, local_admin_count: ?int}
     */
    public function columns(): array
    {
        $totalRamGb = data_get($this->data, 'memory.total_gb');
        $secureBoot = data_get($this->data, 'security.secure_boot');

        return [
            'manufacturer' => $this->limited($this->textAt('system.manufacturer')),
            'model' => $this->limited($this->textAt('system.model')),
            'serial_number' => $this->limited($this->textAt('system.serial_number')),
            'total_ram_gb' => is_numeric($totalRamGb) ? round((float) $totalRamGb, 1) : null,
            'windows_edition' => $this->limited($this->textAt('windows.caption')),
            'windows_build' => $this->limited($this->windowsBuild()),
            'is_bitlocker_on' => $this->security()->isSystemDriveProtected(),
            'is_secure_boot' => is_bool($secureBoot) ? $secureBoot : null,
            'local_admin_count' => $this->users()->localAdminCount(),
        ];
    }

    /**
     * The build with its update revision, such as 22631.4317.
     */
    public function windowsBuild(): ?string
    {
        $build = $this->textAt('windows.build');
        $revision = $this->textAt('windows.ubr');

        return $build === null || $revision === null ? $build : "{$build}.{$revision}";
    }

    private function cpu(): ?string
    {
        $cores = $this->textAt('cpu.cores');
        $threads = $this->textAt('cpu.logical_processors');

        return $this->joined(' · ', $this->textAt('cpu.name'), $cores && $threads ? "{$cores} cores / {$threads} threads" : null);
    }

    private function domain(): ?string
    {
        return data_get($this->data, 'windows.is_domain_joined') === true
            ? $this->textAt('windows.domain')
            : $this->joined(' ', 'Workgroup', $this->textAt('windows.workgroup'));
    }

    private function lastBoot(): ?string
    {
        $lastBoot = $this->carbon(data_get($this->data, 'windows.last_boot'));

        return $lastBoot === null ? null : $lastBoot->format('j M Y, H:i').' · up '.$lastBoot->diffForHumans(syntax: true, parts: 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->data[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    private function limited(?string $value): ?string
    {
        return $value === null ? null : Str::limit($value, 255, '');
    }
}
