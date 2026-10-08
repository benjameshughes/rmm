<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One `rmm du --json` result (schema rmm.du/1). The agent runs on machines we
 * do not control, so fromArray() checks everything and returns null for an
 * unknown schema or a folder tree that does not hang together. Largest files
 * and extensions are extras: a bad row there is dropped, not fatal.
 */
final readonly class DiskScan
{
    /**
     * @param  Collection<int, DiskScanFile>  $topFiles
     * @param  Collection<int, DiskScanExtension>  $extensions
     * @param  array<int, array{0: string, 1: int}>  $errorSample  Path and OS error code
     */
    public function __construct(
        public string $root,
        public ?string $agentVersion,
        public ?Carbon $startedAt,
        public ?int $durationMs,
        public ?int $volumeTotal,
        public ?int $volumeFree,
        public ?string $filesystem,
        public int $allocated,
        public int $files,
        public int $dirs,
        public ?int $reportedUnaccounted,
        public DiskScanTree $tree,
        public Collection $topFiles,
        public Collection $extensions,
        public int $errorCount,
        public array $errorSample,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $tree = DiskScanTree::fromRows($data['nodes'] ?? null);
        $root = $data['root'] ?? null;

        if (($data['schema'] ?? null) !== config('disk_usage.schema') || ! is_string($root) || $root === '' || $tree === null) {
            return null;
        }

        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
        $volume = is_array($data['volume'] ?? null) ? $data['volume'] : [];
        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $started = $data['started_at'] ?? null;

        return new self(
            root: $root,
            agentVersion: is_string($data['agent'] ?? null) ? $data['agent'] : null,
            startedAt: is_string($started) && strtotime($started) !== false ? Carbon::parse($started) : null,
            durationMs: self::integer($data['duration_ms'] ?? null),
            volumeTotal: self::integer($volume['total'] ?? null),
            volumeFree: self::integer($volume['free'] ?? null),
            filesystem: is_string($volume['fs'] ?? null) ? $volume['fs'] : null,
            allocated: self::integer($totals['allocated'] ?? null) ?? $tree->root()->allocated,
            files: self::integer($totals['files'] ?? null) ?? $tree->root()->files,
            dirs: self::integer($totals['dirs'] ?? null) ?? $tree->root()->dirs,
            reportedUnaccounted: self::integer($totals['unaccounted'] ?? null),
            tree: $tree,
            topFiles: self::rows($data['top_files'] ?? null, DiskScanFile::fromRow(...)),
            extensions: self::rows($data['extensions'] ?? null, DiskScanExtension::fromRow(...)),
            errorCount: self::integer($errors['count'] ?? null) ?? 0,
            errorSample: collect(is_array($errors['sample'] ?? null) ? $errors['sample'] : [])
                ->filter(fn (mixed $error): bool => is_array($error) && is_string($error[0] ?? null) && is_int($error[1] ?? null))
                ->map(fn (array $error): array => [$error[0], $error[1]])
                ->values()
                ->all(),
        );
    }

    /**
     * Free-space figures and unaccounted space only mean something for a whole drive.
     */
    public function isDriveRoot(): bool
    {
        return preg_match('/^[A-Za-z]:\\\\$/', $this->root) === 1;
    }

    public function usedBytes(): ?int
    {
        return $this->volumeTotal === null || $this->volumeFree === null ? null : max(0, $this->volumeTotal - $this->volumeFree);
    }

    /**
     * Space the drive reports as used that the scan could not see: shadow
     * copies, unreadable folders, filesystem metadata.
     */
    public function unaccountedBytes(): ?int
    {
        $used = $this->usedBytes();

        return match (true) {
            ! $this->isDriveRoot() => null,
            $this->reportedUnaccounted !== null => $this->reportedUnaccounted,
            $used === null => null,
            default => max(0, $used - $this->allocated),
        };
    }

    public function percentOfVolume(?int $bytes): float
    {
        return $this->volumeTotal > 0 && $bytes !== null ? min(100, $bytes / $this->volumeTotal * 100) : 0.0;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @template TRow
     *
     * @param  callable(mixed): (TRow|null)  $parse
     * @return Collection<int, TRow>
     */
    private static function rows(mixed $rows, callable $parse): Collection
    {
        return collect(is_array($rows) && array_is_list($rows) ? $rows : [])
            ->map($parse)
            ->filter()
            ->values();
    }
}
