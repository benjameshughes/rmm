<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\DTOs\DiskUsage\DiskCulprit;
use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanFile;
use App\DTOs\DiskUsage\DiskScanNode;
use App\Enums\DiskNodeFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The well-known space hogs in a scan (config/disk_usage.php): folders such as
 * each user's Outlook data, Downloads and Recycle Bin, plus the page,
 * hibernation and swap files in the drive root. Recycle Bin folders are
 * named by SID, shown as the user whose profile has that SID when known.
 */
final class FindDiskCulprits
{
    /**
     * @param  array<string, string>  $userNamesBySid  Uppercase SID => profile folder name
     * @return Collection<int, DiskCulprit> Biggest first
     */
    public function __invoke(DiskScan $scan, array $userNamesBySid = []): Collection
    {
        return $this->folders($scan, $userNamesBySid)
            ->concat($this->rootFiles($scan))
            ->reject(fn (DiskCulprit $culprit): bool => $culprit->allocated === 0)
            ->sortByDesc(fn (DiskCulprit $culprit): int => $culprit->allocated)
            ->values();
    }

    /**
     * @param  array<string, string>  $userNamesBySid
     * @return Collection<int, DiskCulprit>
     */
    private function folders(DiskScan $scan, array $userNamesBySid): Collection
    {
        $patterns = collect(config('disk_usage.culprits'))->map(fn (array $culprit): array => [...$culprit, 'regex' => $this->regex($culprit['glob'])]);

        return $scan->tree->nodes()
            ->reject(fn (DiskScanNode $node): bool => $node->has(DiskNodeFlag::Other))
            ->map(function (DiskScanNode $node) use ($scan, $patterns, $userNamesBySid): ?array {
                $relativePath = $scan->tree->pathFromDrive($node->index);
                $culprit = $patterns->first(fn (array $pattern): bool => preg_match($pattern['regex'], $relativePath) === 1);

                if ($culprit === null) {
                    return null;
                }

                preg_match($culprit['regex'], $relativePath, $matches);

                return [
                    'label' => $culprit['label'],
                    'owner' => $this->owner($culprit['owner'], $matches[1] ?? null, $userNamesBySid),
                    'node' => $node,
                ];
            })
            ->filter()
            ->groupBy(fn (array $match): string => $match['label']."\0".$match['owner'])
            ->map(function (Collection $matches) use ($scan): DiskCulprit {
                $biggest = $matches->sortByDesc(fn (array $match): int => $match['node']->allocated)->first();

                return new DiskCulprit(
                    label: $biggest['label'],
                    owner: $biggest['owner'],
                    allocated: $matches->sum(fn (array $match): int => $match['node']->allocated),
                    indexPath: $scan->tree->indexPath($biggest['node']->index),
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, DiskCulprit>
     */
    private function rootFiles(DiskScan $scan): Collection
    {
        $labels = collect(config('disk_usage.root_files'))->mapWithKeys(fn (string $label, string $name): array => [Str::lower($name) => $label]);

        return $scan->isDriveRoot()
            ? $scan->topFiles
                ->filter(fn (DiskScanFile $file): bool => $file->parentIndex === 0 && $labels->has(Str::lower($file->name)))
                ->map(fn (DiskScanFile $file): DiskCulprit => new DiskCulprit(label: $labels->get(Str::lower($file->name)), owner: null, allocated: $file->allocated, indexPath: null))
                ->values()
            : collect();
    }

    /**
     * `*` matches exactly one folder name, captured so it can name the owner.
     */
    private function regex(string $glob): string
    {
        return '/^'.str_replace('\*', '([^\\\\]+)', preg_quote($glob, '/')).'$/i';
    }

    /**
     * @param  array<string, string>  $userNamesBySid
     */
    private function owner(?string $kind, ?string $captured, array $userNamesBySid): ?string
    {
        return match (true) {
            $kind === null || $captured === null => null,
            $kind === 'sid' => $userNamesBySid[Str::upper($captured)] ?? $captured,
            default => $captured,
        };
    }
}
