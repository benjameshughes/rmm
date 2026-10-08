<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use App\Enums\DiskNodeFlag;
use Illuminate\Support\Number;

/**
 * One folder row on the Storage tab: its size against its parent and how
 * much it grew or shrank since the previous scan of the same folder.
 */
final readonly class DiskFolderRow
{
    /**
     * @param  array<int, DiskNodeFlag>  $badges
     * @param  int|null  $change  Bytes since the previous scan; null when there is nothing to compare
     */
    public function __construct(
        public int $index,
        public string $indexPath,
        public string $name,
        public string $path,
        public int $allocated,
        public int $files,
        public float $percentOfParent,
        public array $badges,
        public ?int $change,
        public bool $isNew,
        public bool $canOpen,
        public bool $canScanDeeper,
    ) {}

    public function allocatedForHumans(): string
    {
        return Number::fileSize($this->allocated, precision: 1);
    }

    public function percentForHumans(): string
    {
        return Number::format($this->percentOfParent, precision: 1).'%';
    }

    public function filesForHumans(): string
    {
        return Number::format($this->files);
    }

    public function changeForHumans(): ?string
    {
        return match (true) {
            $this->isNew => 'New',
            $this->change === null || $this->change === 0 => null,
            default => ($this->change > 0 ? '+' : '-').Number::fileSize(abs($this->change), precision: 1),
        };
    }

    /**
     * Growth is what fills a drive, so it reads red.
     */
    public function changeColor(): string
    {
        return match (true) {
            $this->isNew, $this->change > 0 => 'red',
            $this->change < 0 => 'green',
            default => 'zinc',
        };
    }
}
