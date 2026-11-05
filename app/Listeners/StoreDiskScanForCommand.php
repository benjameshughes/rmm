<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DiskUsage\StoreDiskScan;
use App\Enums\CommandStatus;
use App\Events\CommandUpdated;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A finished disk-usage run stores a new scan for the device's Storage tab
 * whenever it printed one, whatever its exit code: a partial scan (some
 * folders unreadable) is still worth showing. StoreDiskScan ignores output
 * without a readable scan.
 */
final class StoreDiskScanForCommand
{
    public function __construct(
        private readonly StoreDiskScan $storeDiskScan,
    ) {}

    public function handle(CommandUpdated $event): void
    {
        if (! in_array($event->status, [CommandStatus::Completed->value, CommandStatus::Failed->value], true)) {
            return;
        }

        $command = DeviceCommand::query()
            ->with(['device', 'script'])
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->where('slug', config('disk_usage.slug')))
            ->find($event->commandId);

        if ($command === null || $command->device->isMonitorOnly) {
            return;
        }

        $depth = $command->parameters['Depth'] ?? config('disk_usage.default_depth');

        ($this->storeDiskScan)(
            device: $command->device,
            data: $command->resultJson(),
            depth: is_numeric($depth) ? (int) $depth : null,
            scannedAt: $command->completed_at ?? now(),
            logContext: ['command_id' => $command->id, 'output_start' => Str::limit((string) $command->output, 200)],
        );
    }
}
