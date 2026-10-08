<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

/**
 * Space taken by one file extension across the scan: `[".ost", allocated, count]`.
 */
final readonly class DiskScanExtension
{
    public function __construct(
        public string $extension,
        public int $allocated,
        public int $count,
    ) {}

    public static function fromRow(mixed $row): ?self
    {
        $isValid = is_array($row)
            && array_is_list($row)
            && count($row) === 3
            && is_string($row[0])
            && is_int($row[1])
            && is_int($row[2]);

        return $isValid ? new self(extension: $row[0], allocated: $row[1], count: $row[2]) : null;
    }
}
