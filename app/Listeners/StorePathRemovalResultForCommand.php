<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DeletePath\StoreDeletedPath;
use App\Actions\DeletePath\StoreQuarantinePurge;
use App\Actions\DeletePath\StoreQuarantineRestore;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * A finished delete, purge or restore records what it did, whatever its
 * exit code: a delete that hit a few locked files still removed the rest.
 * Each action ignores output without its JSON line.
 */
final class StorePathRemovalResultForCommand
{
    public function __construct(
        private readonly StoreDeletedPath $storeDeletedPath,
        private readonly StoreQuarantinePurge $storeQuarantinePurge,
        private readonly StoreQuarantineRestore $storeQuarantineRestore,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if (! in_array($event->status, [CommandStatus::Completed->value, CommandStatus::Failed->value], true)) {
            return;
        }

        $slugs = config('devices.delete_path');

        $command = DeviceCommand::query()
            ->with(['device', 'script', 'queuedBy'])
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->whereIn('slug', [$slugs['slug'], $slugs['purge_slug'], $slugs['restore_slug']]))
            ->find($event->commandId);

        match ($command?->script->slug) {
            null => null,
            $slugs['slug'] => ($this->storeDeletedPath)($command),
            $slugs['purge_slug'] => ($this->storeQuarantinePurge)($command),
            $slugs['restore_slug'] => ($this->storeQuarantineRestore)($command),
        };
    }
}
