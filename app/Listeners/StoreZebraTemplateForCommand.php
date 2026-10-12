<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Printer\StoreZebraTemplate;
use App\Enums\CommandStatus;
use App\Enums\PrinterAction;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;

/**
 * A successful capture-zebra-template run becomes the Zebra template.
 */
final class StoreZebraTemplateForCommand
{
    public function __construct(
        private readonly StoreZebraTemplate $storeZebraTemplate,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if ($event->status !== CommandStatus::Completed->value) {
            return;
        }

        $command = DeviceCommand::query()
            ->with('device')
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->where('slug', PrinterAction::CaptureZebraTemplate->value))
            ->where('exit_code', 0)
            ->find($event->commandId);

        if ($command === null) {
            return;
        }

        ($this->storeZebraTemplate)($command);
    }
}
