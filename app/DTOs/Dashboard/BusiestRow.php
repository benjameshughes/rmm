<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use App\Enums\LoadLevel;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * One device on the Busiest now list: its CPU sparkline and the figures right now.
 */
final readonly class BusiestRow
{
    /**
     * @param  Collection<int, array{time: string, cpu: ?float}>  $sparkline
     */
    public function __construct(
        public Device $device,
        public Collection $sparkline,
        public ?string $cpu,
        public LoadLevel $cpuLevel,
        public ?string $ram,
        public LoadLevel $ramLevel,
    ) {}
}
