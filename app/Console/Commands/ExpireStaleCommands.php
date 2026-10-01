<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CommandStatus;
use App\Models\DeviceCommand;
use Illuminate\Console\Command;

final class ExpireStaleCommands extends Command
{
    /**
     * Extra time allowed on top of a command's own timeout before it is considered abandoned.
     */
    private const GRACE_SECONDS = 300;

    protected $signature = 'commands:expire-stale';

    protected $description = 'Mark sent or running commands as timed out once they outlive their timeout';

    public function handle(): int
    {
        $expired = DeviceCommand::query()
            ->whereIn('status', [CommandStatus::Sent, CommandStatus::Running])
            ->get()
            ->filter(fn (DeviceCommand $command): bool => $this->hasOutlivedTimeout($command))
            ->each(fn (DeviceCommand $command) => $command->markAsTimedOut());

        $this->info("Timed out {$expired->count()} stale commands.");

        return self::SUCCESS;
    }

    private function hasOutlivedTimeout(DeviceCommand $command): bool
    {
        $startedAt = $command->started_at ?? $command->sent_at ?? $command->queued_at;

        return $startedAt !== null
            && $startedAt->copy()->addSeconds($command->timeout_seconds + self::GRACE_SECONDS)->isPast();
    }
}
