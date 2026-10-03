<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CommandStatus;
use App\Models\DeviceCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ExpireStaleCommands extends Command
{
    protected $signature = 'commands:expire-stale';

    protected $description = 'Requeue sent commands the agent never started and time out commands that outlived their timeout';

    public function handle(): int
    {
        $requeued = DeviceCommand::query()
            ->where('status', CommandStatus::Sent)
            ->whereNull('started_at')
            ->where('sent_at', '<=', now()->subSeconds(config('commands.unstarted_requeue_seconds')))
            ->get()
            ->each(function (DeviceCommand $command): void {
                $command->requeue();

                Log::info('command.requeued', [
                    'device_id' => $command->device_id,
                    'command_id' => $command->id,
                ]);
            });

        $expired = DeviceCommand::query()
            ->whereIn('status', [CommandStatus::Sent, CommandStatus::Running])
            ->get()
            ->filter(fn (DeviceCommand $command): bool => $this->hasOutlivedTimeout($command))
            ->each(fn (DeviceCommand $command) => $command->markAsTimedOut());

        $this->info("Requeued {$requeued->count()} unstarted commands.");
        $this->info("Timed out {$expired->count()} stale commands.");

        return self::SUCCESS;
    }

    private function hasOutlivedTimeout(DeviceCommand $command): bool
    {
        $startedAt = $command->started_at ?? $command->sent_at ?? $command->queued_at;

        return $startedAt !== null
            && $startedAt->copy()->addSeconds($command->timeout_seconds + config('commands.stale_grace_seconds'))->isPast();
    }
}
