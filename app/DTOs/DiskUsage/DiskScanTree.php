<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use App\Enums\DiskNodeFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A scan's folders as a tree. Node 0 is the scanned folder and every other
 * node names a parent listed before it, so the tree can never loop. Folders
 * are addressed in the UI by their index path from the root ("3.17"), and
 * matched between scans by their full path.
 */
final readonly class DiskScanTree
{
    /**
     * @param  array<int, DiskScanNode>  $nodes
     * @param  array<int, array<int, int>>  $childIndexes  Parent index => child indexes
     * @param  array<int, string>  $paths  Index => full path
     */
    public function __construct(
        private array $nodes,
        private array $childIndexes,
        private array $paths,
    ) {}

    public static function fromRows(mixed $rows): ?self
    {
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return null;
        }

        $nodes = collect($rows)->map(fn (mixed $row, int $index): ?DiskScanNode => DiskScanNode::fromRow($index, $row));
        $isTree = $nodes->every(fn (?DiskScanNode $node, int $index): bool => $node !== null
            && ($index === 0 ? $node->parentIndex === -1 : $node->parentIndex >= 0 && $node->parentIndex < $index));

        if (! $isTree) {
            return null;
        }

        $paths = [];
        $nodes->each(function (DiskScanNode $node) use (&$paths): void {
            $paths[$node->index] = $node->index === 0 ? $node->name : rtrim($paths[$node->parentIndex], '\\').'\\'.$node->name;
        });

        return new self(
            nodes: $nodes->all(),
            childIndexes: $nodes->skip(1)->groupBy(fn (DiskScanNode $node): int => $node->parentIndex)
                ->map(fn (Collection $children): array => $children->pluck('index')->all())
                ->all(),
            paths: $paths,
        );
    }

    public function root(): DiskScanNode
    {
        return $this->nodes[0];
    }

    public function node(int $index): ?DiskScanNode
    {
        return $this->nodes[$index] ?? null;
    }

    /**
     * Biggest first, with the rolled-up "(other)" last.
     *
     * @return Collection<int, DiskScanNode>
     */
    public function children(int $index): Collection
    {
        return collect($this->childIndexes[$index] ?? [])
            ->map(fn (int $childIndex): DiskScanNode => $this->nodes[$childIndex])
            ->sortBy([
                fn (DiskScanNode $a, DiskScanNode $b): int => $a->has(DiskNodeFlag::Other) <=> $b->has(DiskNodeFlag::Other),
                fn (DiskScanNode $a, DiskScanNode $b): int => $b->allocated <=> $a->allocated,
            ])
            ->values();
    }

    public function hasChildren(int $index): bool
    {
        return ($this->childIndexes[$index] ?? []) !== [];
    }

    /**
     * A folder whose subfolders the scan left out, so scanning it alone would show them.
     */
    public function isPruned(int $index): bool
    {
        return ! $this->hasChildren($index) && (bool) $this->node($index)?->canScanDeeper();
    }

    public function path(int $index): string
    {
        return $this->paths[$index] ?? '';
    }

    /**
     * The path below the drive letter, such as `Users\anna\Downloads`.
     */
    public function pathFromDrive(int $index): string
    {
        return Str::of($this->path($index))->replaceMatches('/^[A-Za-z]:\\\\?/', '')->toString();
    }

    /**
     * From the root down to the folder, for breadcrumbs.
     *
     * @return Collection<int, DiskScanNode>
     */
    public function lineage(int $index): Collection
    {
        $lineage = collect();

        for ($node = $this->node($index); $node !== null; $node = $this->node($node->parentIndex)) {
            $lineage->prepend($node);
        }

        return $lineage;
    }

    public function indexPath(int $index): string
    {
        return $this->lineage($index)->skip(1)->pluck('index')->implode('.');
    }

    /**
     * The folder an index path points at. A path that stops matching, say
     * after a new scan, settles on the deepest folder it still reaches.
     */
    public function resolve(string $indexPath): int
    {
        $index = 0;

        Str::of($indexPath)->explode('.')
            ->filter(fn (string $segment): bool => ctype_digit($segment))
            ->map(fn (string $segment): int => (int) $segment)
            ->each(function (int $child) use (&$index): bool {
                if (! in_array($child, $this->childIndexes[$index] ?? [], true)) {
                    return false;
                }

                $index = $child;

                return true;
            });

        return $index;
    }

    public function indexOfPath(string $path): ?int
    {
        $index = array_search(Str::lower($path), array_map(fn (string $candidate): string => Str::lower($candidate), $this->paths), true);

        return $index === false ? null : $index;
    }

    /**
     * Every real folder's size by its lowercased full path, for comparing scans.
     *
     * @return array<string, int>
     */
    public function allocatedByPath(): array
    {
        return collect($this->nodes)
            ->reject(fn (DiskScanNode $node): bool => $node->has(DiskNodeFlag::Other))
            ->mapWithKeys(fn (DiskScanNode $node): array => [Str::lower($this->paths[$node->index]) => $node->allocated])
            ->all();
    }

    /**
     * @return Collection<int, DiskScanNode>
     */
    public function nodes(): Collection
    {
        return collect($this->nodes);
    }
}
