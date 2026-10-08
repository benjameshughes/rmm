<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use App\Enums\DiskNodeFlag;

/**
 * One folder in a disk scan: `[parent_index, name, allocated, logical, files, dirs, flags]`.
 */
final readonly class DiskScanNode
{
    public function __construct(
        public int $index,
        public int $parentIndex,
        public string $name,
        public int $allocated,
        public int $logical,
        public int $files,
        public int $dirs,
        public int $flags,
    ) {}

    /**
     * Null when the row is not the seven typed values the schema promises.
     */
    public static function fromRow(int $index, mixed $row): ?self
    {
        $isValid = is_array($row)
            && array_is_list($row)
            && count($row) === 7
            && is_int($row[0])
            && is_string($row[1])
            && $row[1] !== ''
            && collect(array_slice($row, 2))->every(fn (mixed $value): bool => is_int($value) && $value >= 0);

        return $isValid ? new self(
            index: $index,
            parentIndex: $row[0],
            name: $row[1],
            allocated: $row[2],
            logical: $row[3],
            files: $row[4],
            dirs: $row[5],
            flags: $row[6],
        ) : null;
    }

    public function has(DiskNodeFlag $flag): bool
    {
        return ($this->flags & $flag->value) === $flag->value;
    }

    /**
     * @return array<int, DiskNodeFlag>
     */
    public function badges(): array
    {
        return array_values(array_filter(DiskNodeFlag::fromMask($this->flags), fn (DiskNodeFlag $flag): bool => $flag->isBadge()));
    }

    public function displayName(): string
    {
        return $this->has(DiskNodeFlag::Other) ? '(other)' : $this->name;
    }

    /**
     * A real folder the scan stopped above, so a deeper scan of it would show more.
     */
    public function canScanDeeper(): bool
    {
        return $this->dirs > 0 && ! $this->has(DiskNodeFlag::Other) && ! $this->has(DiskNodeFlag::LinkNotFollowed);
    }
}
