<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device has sent several metrics reports in a row without any CPU figure,
 * so Netdata on it is running blind or not answering at all.
 */
final class NetdataWentQuiet
{
    use Dispatchable;

    public function __construct(
        public readonly Device $device,
    ) {}
}
