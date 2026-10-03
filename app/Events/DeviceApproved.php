<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class DeviceApproved
{
    use Dispatchable;

    public function __construct(
        public readonly Device $device,
        public readonly User $approvedBy,
    ) {}
}
