<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\DTOs\DeviceAttention;
use App\Enums\AttentionLevel;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * One device on the dashboard's attention list, everything the row prints worked out up front.
 */
final readonly class AttentionRow
{
    /**
     * @param  Collection<int, AttentionChip>  $chips
     */
    public function __construct(
        public DeviceAttention $attention,
        public Device $device,
        public AttentionLevel $severity,
        public string $statusLabel,
        public string $lastSeen,
        public Collection $chips,
    ) {}
}
