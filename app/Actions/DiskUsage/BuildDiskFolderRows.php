<?php

declare(strict_types=1);

namespace App\Actions\DiskUsage;

use App\DTOs\DiskUsage\DiskFolderRow;
use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanNode;
use App\Enums\DiskNodeFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The rows for one folder of a scan: each subfolder biggest first, its share
 * of the folder, and how much it grew or shrank since the previous scan,
 * matched by full path so it survives folders coming and going.
 */
final class BuildDiskFolderRows
{
    /**
     * @return Collection<int, DiskFolderRow>
     */
    public function __invoke(DiskScan $scan, ?DiskScan $previous, int $index): Collection
    {
        $tree = $scan->tree;
        $parentAllocated = $tree->node($index)?->allocated ?? 0;
        $previousSizes = $previous?->tree->allocatedByPath();

        return $tree->children($index)->map(function (DiskScanNode $node) use ($tree, $parentAllocated, $previousSizes): DiskFolderRow {
            $isOther = $node->has(DiskNodeFlag::Other);
            $previousSize = $previousSizes[Str::lower($tree->path($node->index))] ?? null;
            $isCompared = $previousSizes !== null && ! $isOther;

            return new DiskFolderRow(
                index: $node->index,
                indexPath: $tree->indexPath($node->index),
                name: $node->displayName(),
                path: $tree->path($node->index),
                allocated: $node->allocated,
                files: $node->files,
                percentOfParent: $parentAllocated > 0 ? min(100, $node->allocated / $parentAllocated * 100) : 0.0,
                badges: $node->badges(),
                change: $isCompared && $previousSize !== null ? $node->allocated - $previousSize : null,
                isNew: $isCompared && $previousSize === null,
                canOpen: $tree->hasChildren($node->index),
                canScanDeeper: $tree->isPruned($node->index),
                isRollup: $isOther,
            );
        });
    }
}
