<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A PC joined or left a group or tag. Carries the staff accounts that
 * targeted it before the change, so listeners can tell who gained or lost it.
 */
final class DeviceRegrouped
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $previousStaffAccountIds
     */
    public function __construct(
        public readonly Device $device,
        public readonly array $previousStaffAccountIds,
    ) {}
}
