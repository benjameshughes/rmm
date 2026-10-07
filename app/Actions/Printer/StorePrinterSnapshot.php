<?php

declare(strict_types=1);

namespace App\Actions\Printer;

use App\DTOs\Printers\PrinterQueue;
use App\DTOs\Printers\PrinterSnapshot;
use App\Events\DeviceUpdated;
use App\Events\PrintersReported;
use App\Models\Device;
use App\Models\DevicePrinter;
use Illuminate\Support\Facades\DB;

final class StorePrinterSnapshot
{
    public function __construct(private readonly SyncPrinterAlerts $syncPrinterAlerts) {}

    /**
     * Keeps the latest snapshot of each queue, drops queues the PC no longer
     * has, and stamps when a queue or the spooler turned into a problem. The
     * stamps only start while the PC is plainly online; PausePrinterWatch
     * clears them when it stops being so. A stopped spooler cannot list its
     * printers, so the last known queues stay as they were.
     *
     * Every snapshot reaches the device's own page; fleet pages only hear
     * about it when a problem started or cleared.
     */
    public function __invoke(Device $device, PrinterSnapshot $snapshot): void
    {
        $isJudged = $device->isPlainlyOnline;
        $problemsBefore = $this->problemState($device);

        DB::transaction(function () use ($device, $snapshot, $isJudged): void {
            if ($snapshot->isSpoolerAvailable) {
                $this->storePrinters($device, $snapshot, $isJudged);
            }

            $device->forceFill([
                'printers_reported_at' => now(),
                'spooler_down_since' => $snapshot->isSpoolerAvailable || ! $isJudged ? null : ($device->spooler_down_since ?? now()),
            ])->save();
        });

        PrintersReported::dispatch($device->id);

        if ($this->problemState($device) !== $problemsBefore) {
            DeviceUpdated::dispatch($device, true);
        }

        ($this->syncPrinterAlerts)($device);
    }

    private function storePrinters(Device $device, PrinterSnapshot $snapshot, bool $isJudged): void
    {
        $problemSince = $device->problemPrinters()->pluck('problem_since', 'name');

        $snapshot->printers->each(fn (PrinterQueue $queue): DevicePrinter => $device->printers()->updateOrCreate(['name' => $queue->name], [
            'snapshot' => $queue->toArray(),
            'collected_at' => $snapshot->collectedAt,
            'problem_since' => $isJudged && $queue->hasProblem() ? ($problemSince->get($queue->name) ?? now()) : null,
        ]));

        $device->printers()->whereNotIn('name', $snapshot->printers->map(fn (PrinterQueue $queue): string => $queue->name))->delete();
    }

    /**
     * What the header badge, the devices table and the dashboard show about this PC's printing.
     *
     * @return array{bool, bool, array<int, string>}
     */
    private function problemState(Device $device): array
    {
        return [
            $device->isWatchingPrinters,
            $device->spooler_down_since !== null,
            $device->problemPrinters()->orderBy('name')->pluck('name')->all(),
        ];
    }
}
