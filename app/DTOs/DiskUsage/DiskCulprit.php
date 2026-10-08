<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use Illuminate\Support\Number;

/**
 * A well-known space hog a scan found, such as one user's Outlook data or
 * the page file.
 */
final readonly class DiskCulprit
{
    /**
     * @param  string|null  $indexPath  Where to open it in the folder tree; null for a file
     */
    public function __construct(
        public string $label,
        public ?string $owner,
        public int $allocated,
        public ?string $indexPath,
    ) {}

    public function allocatedForHumans(): string
    {
        return Number::fileSize($this->allocated, precision: 1);
    }
}
