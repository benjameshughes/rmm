<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

/**
 * Updates, the software environment and what third parties added (drivers,
 * startup items, services and scheduled tasks) from one system inventory.
 */
final class InventorySoftware
{
    use ReadsInventoryData;

    /**
     * @param  array<string, mixed>  $data  The whole inventory, since these span several top-level keys
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * @return list<string>
     */
    public function pendingRebootReasons(): array
    {
        return $this->strings('updates.pending_reboot.reasons');
    }

    public function isRebootPending(): bool
    {
        return $this->pendingRebootReasons() !== [];
    }

    public function hotfixes(): InventoryTable
    {
        return $this->table('updates.hotfixes', [
            'Update' => fn (array $hotfix): ?string => $this->text($hotfix['id'] ?? null),
            'Installed' => fn (array $hotfix): ?string => $this->date($hotfix['installed_on'] ?? null),
            'Description' => fn (array $hotfix): ?string => $this->text($hotfix['description'] ?? null),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $release = $this->textAt('software_environment.dotnet_framework.release');

        return $this->facts([
            '.NET Framework' => $this->joined(' ', $this->textAt('software_environment.dotnet_framework.version'), $release === null ? null : "(release {$release})"),
            'PowerShell' => $this->joined(', ', ...$this->strings('software_environment.powershell_versions')),
        ]);
    }

    /**
     * @return list<string>
     */
    public function optionalFeatures(): array
    {
        return $this->strings('software_environment.optional_features');
    }

    public function drivers(): InventoryTable
    {
        return $this->table('drivers', [
            'Device' => fn (array $driver): ?string => $this->text($driver['device_name'] ?? null),
            'Provider' => fn (array $driver): ?string => $this->text($driver['provider'] ?? null),
            'Version' => fn (array $driver): ?string => $this->text($driver['version'] ?? null),
            'Date' => fn (array $driver): ?string => $this->date($driver['date'] ?? null),
            'Signed' => fn (array $driver): ?string => $this->yesNo($driver['is_signed'] ?? null),
        ]);
    }

    public function startup(): InventoryTable
    {
        return $this->table('startup', [
            'Name' => fn (array $item): ?string => $this->text($item['name'] ?? null),
            'Command' => fn (array $item): ?string => $this->text($item['command'] ?? null),
            'Location' => fn (array $item): ?string => $this->text($item['location'] ?? null),
            'User' => fn (array $item): ?string => $this->text($item['user'] ?? null),
        ]);
    }

    public function services(): InventoryTable
    {
        return $this->table('services_non_microsoft', [
            'Name' => fn (array $service): ?string => $this->text($service['display_name'] ?? null) ?? $this->text($service['name'] ?? null),
            'Service' => fn (array $service): ?string => $this->text($service['name'] ?? null),
            'State' => fn (array $service): ?string => $this->text($service['state'] ?? null),
            'Start mode' => fn (array $service): ?string => $this->text($service['start_mode'] ?? null),
        ]);
    }

    public function scheduledTasks(): InventoryTable
    {
        return $this->table('scheduled_tasks_non_microsoft', [
            'Path' => fn (array $task): ?string => $this->text($task['path'] ?? null),
            'Name' => fn (array $task): ?string => $this->text($task['name'] ?? null),
            'State' => fn (array $task): ?string => $this->text($task['state'] ?? null),
        ]);
    }
}
