<?php

declare(strict_types=1);

namespace App\DTOs\Printers;

use App\Enums\PrinterAction;
use App\Models\DeviceCommand;
use Illuminate\Support\Collection;

/**
 * The print queue commands still in flight on one device, so each button on
 * the Printers tab can show its own command's progress instead of running again.
 */
final readonly class PrinterCommands
{
    /**
     * @param  Collection<int, DeviceCommand>  $commands  In flight, with their scripts, oldest first
     */
    public function __construct(private Collection $commands) {}

    /**
     * The newest in-flight run of the action for that printer and job.
     */
    public function for(PrinterAction $action, ?string $printerName = null, ?int $jobId = null): ?DeviceCommand
    {
        return $this->commands->last(fn (DeviceCommand $command): bool => $command->script?->slug === $action->value
            && ($command->parameters['PrinterName'] ?? null) === $printerName
            && ($command->parameters['JobId'] ?? null) === ($jobId === null ? null : (string) $jobId));
    }
}
