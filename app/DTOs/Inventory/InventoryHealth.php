<?php

declare(strict_types=1);

namespace App\DTOs\Inventory;

use Illuminate\Support\Collection;

/**
 * What is wrong with a Windows PC right now, from its latest system inventory: the Overview tab's status tiles,
 * the first few System log errors with the rest held back, and any devices that are not working.
 */
final class InventoryHealth
{
    /**
     * @param  Collection<int, InventoryEvent>  $systemErrors  Shown straight away
     * @param  Collection<int, InventoryEvent>  $moreSystemErrors  Shown on request
     * @param  Collection<int, InventoryCheck>  $deviceProblems
     */
    public function __construct(
        public readonly InventoryCheck $systemErrorsStatus,
        public readonly string $systemErrorsPeriod,
        public readonly string $systemErrorsWindow,
        public readonly Collection $systemErrors,
        public readonly Collection $moreSystemErrors,
        public readonly InventoryCheck $deviceProblemsStatus,
        public readonly Collection $deviceProblems,
        public readonly bool $hasDeviceProblems,
        public readonly InventoryCheck $securityStatus,
        public readonly InventoryCheck $restartStatus,
        public readonly ?string $restartReasons,
    ) {}

    public static function from(SystemInventory $inventory): self
    {
        $software = $inventory->software();
        $hardware = $inventory->hardware();
        $errors = $software->systemErrors();
        $preview = config('inventory.overview_error_preview');
        $deviceProblemsStatus = $hardware->deviceProblemsCheck();

        return new self(
            $software->systemErrorsCheck(),
            'Last '.config('inventory.recent_error_days').' days',
            $software->systemErrorsWindow(),
            $errors->take($preview)->values(),
            $errors->skip($preview)->values(),
            $deviceProblemsStatus,
            $hardware->deviceProblems(),
            $deviceProblemsStatus->needsAttention(),
            $inventory->security()->postureCheck(),
            $software->restartCheck(),
            $software->pendingRebootReasonsForHumans(),
        );
    }
}
