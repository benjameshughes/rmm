<?php

declare(strict_types=1);

namespace App\Actions\ServerBackup;

use App\Events\ServerBackupsReported;
use App\Models\Device;
use App\Models\ServerBackupJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Stores the `backups` section of a server's metrics report: one entry per
 * backup status file. The agent sends the same entries every minute until a
 * file changes, so a job is only rewritten (and its snapshots stored) when
 * its file's modified time, its last run or its error moved; otherwise only
 * last_reported_at is touched. A job missing from the report is left alone
 * and turns "status file missing" once it has not been reported for
 * forget_missing_after_hours.
 *
 * The report is untrusted: entries that are not well formed are logged and
 * skipped without failing the rest, free text is cut to its column, and
 * unknown keys are ignored.
 */
final class StoreServerBackups
{
    public function __construct(private readonly StoreServerBackupSnapshots $storeSnapshots) {}

    public function __invoke(Device $device, mixed $backups): void
    {
        if (! is_array($backups) || ! array_is_list($backups)) {
            Log::warning('server-backups.ignored', ['device_id' => $device->id, 'reason' => 'backups is not a list']);

            return;
        }

        $jobs = $device->serverBackupJobs()->get()->keyBy('job');

        $entries = collect($backups)
            ->take(config('backup.servers.max_jobs'))
            ->filter(fn (mixed $entry, int $index): bool => $this->isWellFormed($device, $entry, $index))
            ->unique('job')
            ->values();

        $changed = $entries
            ->filter(fn (array $entry): bool => $this->hasChanged($jobs->get($entry['job']), $entry))
            ->each(fn (array $entry) => $this->store($device, $entry));

        if ($entries->isNotEmpty()) {
            $device->serverBackupJobs()->whereIn('job', $entries->pluck('job'))->update(['last_reported_at' => now()]);
        }

        ServerBackupsReported::dispatch($device->id, $changed->isNotEmpty());
    }

    private function isWellFormed(Device $device, mixed $entry, int $index): bool
    {
        $validator = Validator::make(is_array($entry) ? $entry : [], [
            'job' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,64}$/'],
            'tool' => ['nullable', 'string'],
            'repository' => ['nullable', 'string'],
            'exit_code' => ['nullable', 'integer'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'file_modified_at' => ['nullable', 'date'],
            'snapshot_count' => ['nullable', 'integer', 'min:0'],
            'snapshots' => ['nullable', 'array'],
            'stats' => ['nullable', 'array'],
            'error' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('server-backups.entry-skipped', ['device_id' => $device->id, 'index' => $index, 'errors' => $validator->errors()->all()]);
        }

        return $validator->passes();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function hasChanged(?ServerBackupJob $job, array $entry): bool
    {
        return $job === null
            || ! $this->isSameMoment($job->status_file_modified_at, $entry['file_modified_at'] ?? null)
            || ! $this->isSameMoment($job->finished_at, $entry['finished_at'] ?? null)
            || $job->last_error !== $this->error($entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function store(Device $device, array $entry): void
    {
        DB::transaction(function () use ($device, $entry): void {
            $error = $this->error($entry);
            $fileModifiedAt = $this->moment($entry['file_modified_at'] ?? null);

            if ($error !== null) {
                $device->serverBackupJobs()->updateOrCreate(['job' => $entry['job']], [
                    'last_error' => $error,
                    'status_file_modified_at' => $fileModifiedAt,
                    'last_reported_at' => now(),
                ]);

                return;
            }

            $job = $device->serverBackupJobs()->updateOrCreate(['job' => $entry['job']], [
                'tool' => $this->text($entry['tool'] ?? null, 32),
                'repository' => $this->text($entry['repository'] ?? null, 500),
                'last_exit_code' => $entry['exit_code'] ?? null,
                'started_at' => $this->moment($entry['started_at'] ?? null),
                'finished_at' => $this->moment($entry['finished_at'] ?? null),
                'status_file_modified_at' => $fileModifiedAt,
                'snapshot_count' => $entry['snapshot_count'] ?? null,
                ...$this->stats($entry['stats'] ?? null),
                'last_error' => null,
                'last_reported_at' => now(),
            ]);

            ($this->storeSnapshots)($job, $entry['snapshots'] ?? [], $entry['snapshot_count'] ?? null);
        });
    }

    /**
     * @return array{total_size: ?int, total_uncompressed_size: ?int, compression_ratio: ?float, compression_space_saving: ?float, total_blob_count: ?int}
     */
    private function stats(mixed $stats): array
    {
        $stats = collect(is_array($stats) ? $stats : [])
            ->map(fn (mixed $value): ?float => is_numeric($value) && $value >= 0 ? (float) $value : null);

        $whole = fn (string $key): ?int => $stats->get($key) === null ? null : (int) $stats->get($key);

        return [
            'total_size' => $whole('total_size'),
            'total_uncompressed_size' => $whole('total_uncompressed_size'),
            'compression_ratio' => $stats->get('compression_ratio'),
            'compression_space_saving' => $stats->get('compression_space_saving'),
            'total_blob_count' => $whole('total_blob_count'),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function error(array $entry): ?string
    {
        return $this->text($entry['error'] ?? null, 1000);
    }

    private function text(?string $value, int $limit): ?string
    {
        return blank($value) ? null : Str::limit($value, $limit - 3);
    }

    private function moment(?string $value): ?CarbonInterface
    {
        return $value === null ? null : Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * Stored times keep whole seconds, so compare at that precision.
     */
    private function isSameMoment(?CarbonInterface $stored, ?string $reported): bool
    {
        return $stored?->getTimestamp() === $this->moment($reported)?->getTimestamp();
    }
}
