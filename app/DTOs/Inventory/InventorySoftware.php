<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Illuminate\Support\Collection;

/**
 * Updates, the software environment, what third parties added (drivers,
 * startup items, services and scheduled tasks), listening ports and recent
 * System log errors from one system inventory.
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

    public function pendingRebootReasonsForHumans(): ?string
    {
        return $this->joined(', ', ...$this->pendingRebootReasons());
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

    public function listeningPorts(): InventoryTable
    {
        return $this->table('listening_ports', [
            'Port' => fn (array $listener): ?string => $this->text($listener['port'] ?? null),
            'Address' => fn (array $listener): ?string => $this->text($listener['address'] ?? null),
            'Process' => fn (array $listener): ?string => $this->text($listener['process_name'] ?? null),
            'PID' => fn (array $listener): ?string => $this->text($listener['process_id'] ?? null),
        ]);
    }

    /**
     * Critical and Error events from the System log, newest first. Empty when there were none or the inventory predates the check.
     *
     * @return Collection<int, InventoryEvent>
     */
    public function systemErrors(): Collection
    {
        return $this->rows('recent_errors')->map(fn (array $event): InventoryEvent => new InventoryEvent(
            $this->dateTime($event['time'] ?? null),
            $this->carbon($event['time'] ?? null)?->diffForHumans(),
            $this->text($event['level'] ?? null) ?? 'Error',
            $this->text($event['provider'] ?? null),
            $this->text($event['event_id'] ?? null),
            $this->text($event['message'] ?? null),
        ));
    }

    /**
     * The headline for the system errors tile: red with any Critical event, amber with only errors,
     * green when the log was clean, zinc when the inventory predates the check. A full list says "25+",
     * since the script stops counting there.
     */
    public function systemErrorsCheck(): InventoryCheck
    {
        if (! is_array($this->data['recent_errors'] ?? null)) {
            return new InventoryCheck('System errors', 'Not reported', 'zinc');
        }

        $errors = $this->systemErrors();
        $limit = config('inventory.recent_error_limit');
        $count = $errors->count() >= $limit ? "{$limit}+" : (string) $errors->count();

        return match (true) {
            $errors->isEmpty() => new InventoryCheck('System errors', 'None', 'green'),
            $errors->contains(fn (InventoryEvent $event): bool => $event->isCritical()) => new InventoryCheck('System errors', $count, 'red'),
            default => new InventoryCheck('System errors', $count, 'amber'),
        };
    }

    /**
     * What the System errors list covers, for its description.
     */
    public function systemErrorsWindow(): string
    {
        return 'Critical and Error events from the Windows System log in the '.config('inventory.recent_error_days').' days before the inventory ran, newest first.';
    }

    public function restartCheck(): InventoryCheck
    {
        return $this->isRebootPending()
            ? new InventoryCheck('Restart', 'Pending', 'amber')
            : new InventoryCheck('Restart', 'Not needed', 'green');
    }
}
