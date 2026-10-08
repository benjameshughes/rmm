<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use Illuminate\Support\Carbon;

/**
 * One of a scan's largest files: `[parent_node_index, name, allocated, logical, modified, attributes]`.
 */
final readonly class DiskScanFile
{
    public function __construct(
        public int $parentIndex,
        public string $name,
        public int $allocated,
        public int $logical,
        public ?Carbon $modifiedAt,
    ) {}

    /**
     * Null when the row does not carry a parent, a name and two sizes.
     */
    public static function fromRow(mixed $row): ?self
    {
        $isValid = is_array($row)
            && array_is_list($row)
            && count($row) >= 4
            && is_int($row[0])
            && is_string($row[1])
            && is_int($row[2])
            && is_int($row[3]);

        if (! $isValid) {
            return null;
        }

        $modified = $row[4] ?? null;

        return new self(
            parentIndex: $row[0],
            name: $row[1],
            allocated: $row[2],
            logical: $row[3],
            modifiedAt: is_string($modified) && strtotime($modified) !== false ? Carbon::parse($modified) : null,
        );
    }
}
