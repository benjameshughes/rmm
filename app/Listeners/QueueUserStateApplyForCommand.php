<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\UserState\QueueUserStateApply;
use App\Enums\CommandStatus;
use App\Enums\UserStateScript;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * A capture-user-state run that completes hands over to its apply on the
 * new PC. Anything else, failures and timeouts included, queues nothing:
 * the failed capture shows on the old PC's Commands tab like any command.
 */
final class QueueUserStateApplyForCommand
{
    public function __construct(
        private readonly QueueUserStateApply $queueApply,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if (CommandStatus::from($event->status) !== CommandStatus::Completed) {
            return;
        }

        $capture = DeviceCommand::query()
            ->with(['device', 'script', 'queuedBy'])
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->where('slug', UserStateScript::Capture->value))
            ->find($event->commandId);

        if ($capture !== null) {
            ($this->queueApply)($capture);
        }
    }
}
