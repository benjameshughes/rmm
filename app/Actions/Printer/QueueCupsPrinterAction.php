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
 * Queues Add CUPS Printer or Remove CUPS Printer on a device for one CUPS
 * queue, with the server and port left to the script's defaults.
 */
final class QueueCupsPrinterAction
{
    public function __construct(
        private readonly ExecuteScriptOnDevice $executeScript,
        private readonly ValidateScriptParameterValues $validateParameters,
    ) {}

    public function __invoke(PrinterAction $action, Device $device, User $user, string $cupsQueue, string $displayName): DeviceCommand
    {
        $script = Script::findSystem($action->value);
        $values = ['Printer' => $cupsQueue, 'DisplayName' => $displayName];

        return ($this->executeScript)($script, $device, $user, parameters: ($this->validateParameters)($script, $values, 'printer'));
    }
}
