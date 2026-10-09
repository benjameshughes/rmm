<?php

declare(strict_types=1);

namespace App\Actions\ServerBackup;

use App\Models\ServerBackupJob;
use App\Models\ServerBackupSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Stores the snapshots one status file listed: restic's newest
 * (max_snapshots at most), newest first. Each is upserted by its id.
 *
 * Pruning, so `restic forget` shows up without losing history the agent no
 * longer sends:
 * - Inside the window (from the oldest snapshot sent onwards) the list is
 *   complete, so a stored snapshot that is not in it was forgotten and is
 *   deleted.
 * - Older than the window, restic still holds snapshot_count minus the
 *   snapshots sent. That many of the newest stored ones are kept and any
 *   older are deleted. Without a count, everything older is kept.
 *
 * Afterwards the job's latest snapshot time, its size and the baseline
 * that size is compared with are written to the job, so its health needs
 * no query.
 */
final class StoreServerBackupSnapshots
{
    /**
     * @param  array<int, mixed>  $snapshots  Raw restic snapshot objects, newest first
     */
    public function __invoke(ServerBackupJob $job, array $snapshots, ?int $snapshotCount): void
    {
        $rows = collect($snapshots)
            ->take(config('backup.servers.max_snapshots'))
            ->map(fn (mixed $snapshot): ?array => $this->row($job, $snapshot))
            ->filter()
            ->unique('snapshot_id')
            ->values();

        $rows->chunk(100)->each(fn (Collection $chunk) => ServerBackupSnapshot::query()->upsert(
            $chunk->map(fn (array $row): array => [...$row, 'created_at' => now(), 'updated_at' => now()])->values()->all(),
            uniqueBy: ['server_backup_job_id', 'snapshot_id'],
            update: [...array_keys($rows->first()), 'updated_at'],
        ));

        $this->prune($job, $rows, $snapshotCount);
        $this->summarise($job);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(ServerBackupJob $job, mixed $snapshot): ?array
    {
        $validator = Validator::make(is_array($snapshot) ? $snapshot : [], [
            'id' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{8,64}$/'],
            'short_id' => ['nullable', 'string', 'max:16'],
            'time' => ['required', 'date'],
            'hostname' => ['nullable', 'string'],
            'paths' => ['nullable', 'array'],
            'tags' => ['nullable', 'array'],
            'summary' => ['nullable', 'array'],
            'summary.backup_start' => ['nullable', 'date'],
            'summary.backup_end' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            Log::warning('server-backups.snapshot-skipped', ['server_backup_job_id' => $job->id, 'errors' => $validator->errors()->all()]);

            return null;
        }

        $summary = $snapshot['summary'] ?? [];
        $count = fn (string $key): ?int => is_numeric($summary[$key] ?? null) && $summary[$key] >= 0 ? (int) $summary[$key] : null;

        return [
            'server_backup_job_id' => $job->id,
            'snapshot_id' => Str::lower($snapshot['id']),
            'short_id' => $snapshot['short_id'] ?? substr($snapshot['id'], 0, 8),
            'taken_at' => $this->moment($snapshot['time']),
            'hostname' => blank($snapshot['hostname'] ?? null) ? null : Str::limit($snapshot['hostname'], 252),
            'paths' => $this->strings($snapshot['paths'] ?? null),
            'tags' => $this->strings($snapshot['tags'] ?? null),
            'duration_seconds' => $this->duration($summary['backup_start'] ?? null, $summary['backup_end'] ?? null),
            'files_new' => $count('files_new'),
            'files_changed' => $count('files_changed'),
            'files_unmodified' => $count('files_unmodified'),
            'total_files_processed' => $count('total_files_processed'),
            'total_bytes_processed' => $count('total_bytes_processed'),
            'data_added' => $count('data_added'),
            'data_added_packed' => $count('data_added_packed'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function prune(ServerBackupJob $job, Collection $rows, ?int $snapshotCount): void
    {
        if ($rows->isEmpty()) {
            if ($snapshotCount === 0) {
                $job->snapshots()->delete();
            }

            return;
        }

        $oldestSent = $rows->min('taken_at');

        $job->snapshots()
            ->where('taken_at', '>=', $oldestSent)
            ->whereNotIn('snapshot_id', $rows->pluck('snapshot_id'))
            ->delete();

        if ($snapshotCount === null) {
            return;
        }

        $forgotten = $job->snapshots()
            ->where('taken_at', '<', $oldestSent)
            ->latest('taken_at')
            ->pluck('id')
            ->slice(max(0, $snapshotCount - $rows->count()));

        $forgotten->chunk(500)->each(fn (Collection $ids) => ServerBackupSnapshot::query()->whereKey($ids->values())->delete());
    }

    private function summarise(ServerBackupJob $job): void
    {
        $latest = $job->snapshots()->latest('taken_at')->first();

        $baseline = $latest === null ? null : $job->snapshots()
            ->where('taken_at', '<', $latest->taken_at)
            ->where('total_bytes_processed', '>', 0)
            ->latest('taken_at')
            ->limit(config('backup.servers.shrink_baseline_snapshots'))
            ->pluck('total_bytes_processed')
            ->median();

        $job->update([
            'latest_snapshot_at' => $latest?->taken_at,
            'latest_bytes_processed' => $latest?->total_bytes_processed,
            'baseline_bytes_processed' => $baseline === null ? null : (int) round($baseline),
        ]);
    }

    /**
     * Up to 50 strings, each cut to 1,000 characters, as JSON for the upsert.
     */
    private function strings(mixed $values): ?string
    {
        if (! is_array($values)) {
            return null;
        }

        return json_encode(collect($values)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->take(50)
            ->map(fn (string $value): string => Str::limit($value, 997))
            ->values()
            ->all());
    }

    private function duration(?string $startedAt, ?string $endedAt): ?float
    {
        return $startedAt === null || $endedAt === null
            ? null
            : max(0.0, round($this->moment($startedAt)->diffInMilliseconds($this->moment($endedAt)) / 1000, 1));
    }

    private function moment(string $value): CarbonInterface
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
