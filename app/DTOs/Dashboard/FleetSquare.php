<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Enums\SignalColor;
use App\Models\Device;

/**
 * One device on the fleet grid: its signal colour (offline red, then attention amber, then its status),
 * a circle when it is monitor only, and the tooltip figures ready to print.
 */
final readonly class FleetSquare
{
    public function __construct(
        public Device $device,
        public SignalColor $signal,
        public bool $isMonitorOnly,
        public string $statusLabel,
        public bool $needsAttention,
        public ?string $cpu,
        public ?string $ram,
        public ?string $worstDisk,
        public string $lastSeen,
    ) {}
}
