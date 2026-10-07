<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\Actions\Script\ExecuteScriptOnDevice;
use App\Actions\Script\ValidateScriptParameterValues;
use App\Enums\PrinterAction;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;

/**
 * Queues one of the print queue scripts on a device. The printer name and job
 * ID travel as script parameters, so the agent version gate in
 * ExecuteScriptOnDevice applies.
 */
final class QueuePrinterAction
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    public function __invoke(PrinterAction $action, Device $device, User $user, ?string $printerName = null, ?int $jobId = null): DeviceCommand
    {
        $script = Script::findSystem($action->value);
        $values = collect(['PrinterName' => $printerName, 'JobId' => $jobId])->reject(fn (string|int|null $value): bool => $value === null)->all();

        return ($this->executeScript)($script, $device, $user, parameters: ($this->validateParameters)($script, $values, 'printer'));
    }
}
