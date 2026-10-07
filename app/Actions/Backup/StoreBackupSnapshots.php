<?php

declare(strict_types=1);

namespace App\Actions\Backup;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Replaces a device's snapshot list with what a successful backup-snapshots
 * run reported. Entries without a hex ID or a readable time are dropped.
 */
final class StoreBackupSnapshots
{
    public function __invoke(DeviceCommand $command): void
    {
        $result = $command->resultJson();

        if (($result['status'] ?? null) !== 'ok') {
            return;
        }

        $device = $command->device;
        $snapshots = $this->snapshots(Arr::wrap($result['snapshots'] ?? []));

        DB::transaction(function () use ($device, $snapshots): void {
            $device->backupSnapshots()->delete();
            $snapshots->chunk(200)->each(fn (Collection $chunk) => $device->backupSnapshots()->createMany($chunk->all()));
            $device->forceFill(['backup_snapshots_listed_at' => now()])->save();
        });
    }

    /**
     * @param  array<int, mixed>  $snapshots
     * @return Collection<int, array{snapshot_id: string, short_id: string, taken_at: Carbon, paths: array<int, string>, files: ?int, bytes: ?int, bytes_added: ?int}>
     */
    private function snapshots(array $snapshots): Collection
    {
        return collect($snapshots)
            ->filter(fn (mixed $snapshot): bool => is_array($snapshot)
                && preg_match('/^[0-9a-f]{64}$/', (string) ($snapshot['id'] ?? '')) === 1
                && $this->takenAt($snapshot['time'] ?? null) !== null)
            ->unique('id')
            ->map(fn (array $snapshot): array => [
                'snapshot_id' => $snapshot['id'],
                'short_id' => substr($snapshot['id'], 0, 8),
                'taken_at' => $this->takenAt($snapshot['time']),
                'paths' => collect(Arr::wrap($snapshot['paths'] ?? []))->filter(fn (mixed $path): bool => is_string($path))->values()->all(),
                'files' => is_numeric($snapshot['files'] ?? null) ? (int) $snapshot['files'] : null,
                'bytes' => is_numeric($snapshot['bytes'] ?? null) ? (int) $snapshot['bytes'] : null,
                'bytes_added' => is_numeric($snapshot['added'] ?? null) ? (int) $snapshot['added'] : null,
            ])
            ->values();
    }

    /**
     * restic writes nanoseconds, which PHP cannot parse, so they are cut to microseconds.
     */
    private function takenAt(mixed $time): ?Carbon
    {
        if (! is_string($time)) {
            return null;
        }

        $parsed = date_create(Str::replaceMatches('/(\.\d{6})\d+/', '$1', $time));

        return $parsed === false ? null : Carbon::instance($parsed)->utc();
    }
}
