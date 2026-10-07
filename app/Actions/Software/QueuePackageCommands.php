<?php

declare(strict_types=1);

namespace App\Actions\Software;

use App\Actions\Device\BulkExecuteScript;
use App\Actions\Script\ValidateScriptParameterValues;
use App\DTOs\Software\PackageCommandPlan;
use App\Models\Device;
use App\Models\Script;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Queues a planned install, upgrade or uninstall: one command per package per
 * device. Every package ID is checked before anything is queued, so a bad one
 * never leaves a half-queued batch. Devices whose agent is too old for
 * parameters are skipped by BulkExecuteScript.
 */
final readonly class QueuePackageCommands
{
    public function __construct(
        private BulkExecuteScript $bulkExecute,
        private ValidateScriptParameterValues $validateParameters,
    ) {}

    /**
     * @return int How many commands were queued
     *
     * @throws ValidationException
     */
    public function __invoke(PackageCommandPlan $plan, User $user, bool $closeAppFirst = false): int
    {
        $script = Script::findSystem($plan->action->value);

        $parametersByPackage = $plan->devicesByPackage->map(fn (Collection $devices, int|string $packageId): array => ($this->validateParameters)(
            $script,
            ['PackageId' => (string) $packageId, ...($plan->action->canCloseApp() ? ['CloseApp' => $closeAppFirst] : [])],
            'packageId',
        ));

        return $plan->devicesByPackage
            ->map(fn (Collection $devices, int|string $packageId): int => ($this->bulkExecute)($script, $devices, $user, $parametersByPackage->get($packageId)))
            ->sum();
    }
}
