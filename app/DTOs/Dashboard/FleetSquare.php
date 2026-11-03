<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Models\Device;

/**
 * One device on the fleet grid: colour is its status, a circle means monitor only,
 * and an outline means it is on the attention list. The tooltip figures come ready to print.
 */
final readonly class FleetSquare
{
    public function __construct(
        public Device $device,
        public string $statusLabel,
        public bool $needsAttention,
        public ?string $cpu,
        public ?string $ram,
        public ?string $worstDisk,
        public string $lastSeen,
    ) {}

    public function classes(): string
    {
        return collect([
            $this->device->statusDotClass(),
            $this->device->isMonitorOnly ? 'rounded-full' : 'rounded-[3px]',
            $this->needsAttention ? 'outline outline-1 outline-offset-1 outline-amber-500' : null,
        ])->filter()->implode(' ');
    }
}
