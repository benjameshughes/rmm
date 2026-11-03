<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\DTOs\DeviceAttention;
use Illuminate\Support\Collection;

/**
 * One device on the dashboard's attention list, with its problems already turned into chips.
 */
final readonly class AttentionRow
{
    /**
     * @param  Collection<int, AttentionChip>  $chips
     */
    public function __construct(
        public DeviceAttention $attention,
        public Collection $chips,
    ) {}
}
