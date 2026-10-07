<?php

declare(strict_types=1);

namespace App\DTOs\Software;

use App\Enums\SoftwareCoverage;
use App\Enums\SoftwareSource;

/**
 * What the software lists are narrowed to. A group or tag also scopes the
 * device counts and the devices a bulk action reaches.
 */
final readonly class SoftwareFilters
{
    public function __construct(
        public string $search = '',
        public ?SoftwareSource $source = null,
        public bool $isOutdatedOnly = false,
        public ?SoftwareCoverage $coverage = null,
        public ?int $groupId = null,
        public ?int $tagId = null,
    ) {}

    public function isFiltered(): bool
    {
        return $this->search !== ''
            || $this->source !== null
            || $this->isOutdatedOnly
            || $this->coverage !== null
            || $this->groupId !== null
            || $this->tagId !== null;
    }
}
