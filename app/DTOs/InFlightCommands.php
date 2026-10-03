<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Collection;

/**
 * What a device is doing or about to do, for the strip at the top of its Overview.
 */
final class InFlightCommands
{
    /**
     * @param  Collection<int, DeviceCommand>  $pending  Oldest first, the order the agent takes them
     */
    public function __construct(
        public readonly ?DeviceCommand $running,
        public readonly Collection $pending,
        public readonly bool $isWaitingForWake,
    ) {}

    /**
     * @param  Collection<int, DeviceCommand>  $commands  The device's in-flight commands
     */
    public static function from(Collection $commands, Device $device): self
    {
        $pending = $commands
            ->filter(fn (DeviceCommand $command): bool => $command->status === CommandStatus::Pending)
            ->sortBy([['queued_at', 'asc'], ['id', 'asc']])
            ->values();

        return new self(
            running: $commands
                ->reject(fn (DeviceCommand $command): bool => $command->status === CommandStatus::Pending)
                ->sortByDesc(fn (DeviceCommand $command): int => ($command->started_at ?? $command->sent_at ?? $command->queued_at)?->getTimestamp() ?? 0)
                ->first(),
            pending: $pending,
            isWaitingForWake: $pending->isNotEmpty() && ! $device->isOnline,
        );
    }

    public static function none(): self
    {
        return new self(null, collect(), false);
    }

    public function isEmpty(): bool
    {
        return $this->running === null && $this->pending->isEmpty();
    }

    public function nextPending(): ?DeviceCommand
    {
        return $this->pending->first();
    }

    public function queuedForHumans(): string
    {
        return $this->pending->count().' queued';
    }
}
