<?php

declare(strict_types=1);

namespace App\DTOs\Trends;

use App\Enums\TrendChangeKind;
use App\Models\DeviceCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One kind of change made across the fleet in the period: a package removed, a script run.
 */
final readonly class TrendChange
{
    /**
     * @param  Collection<int, DeviceCommand>  $latestCommands  each device's latest run, newest first
     * @param  array<int, int>  $runsByDevice  keyed by device ID
     */
    public function __construct(
        public string $key,
        public TrendChangeKind $kind,
        public string $subject,
        public ?string $href,
        public Collection $latestCommands,
        public array $runsByDevice,
        public int $runCount,
        public bool $isScheduled,
        public Carbon $firstAt,
        public Carbon $lastAt,
    ) {}

    public function summary(): string
    {
        return $this->kind->summary($this->subject, $this->deviceCount());
    }

    public function deviceCount(): int
    {
        return count($this->runsByDevice);
    }

    /**
     * "Restart ×3" when it ran more than once on that device.
     */
    public function labelFor(int $deviceId): string
    {
        $runs = $this->runsByDevice[$deviceId] ?? 0;

        return $this->kind->deviceLabel($this->subject).($runs > 1 ? " ×{$runs}" : '');
    }

    public function runsForHumans(): string
    {
        return $this->runCount.' '.str('run')->plural($this->runCount);
    }

    public function datesForHumans(): string
    {
        $first = $this->firstAt->inDisplayTimezone();
        $last = $this->lastAt->inDisplayTimezone();

        return $first->isSameDay($last) ? $last->format('j M') : $first->format('j M').' – '.$last->format('j M');
    }

    /** @return Collection<int, DeviceCommand> */
    public function visibleCommands(): Collection
    {
        return $this->latestCommands->take(config('trends.device_chips'));
    }

    public function hiddenDeviceCount(): int
    {
        return max(0, $this->latestCommands->count() - config('trends.device_chips'));
    }
}
