<?php

declare(strict_types=1);

namespace App\DTOs\DiskUsage;

use App\Enums\PathKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * What the latest disk scan measured for one file or folder, shown before it is deleted.
 */
final readonly class ScannedPath
{
    public function __construct(
        public PathKind $kind,
        public int $allocated,
        public int $files,
        public ?Carbon $modifiedAt,
        public Carbon $scannedAt,
    ) {}

    public function sizeForHumans(): string
    {
        return Number::fileSize($this->allocated, precision: 1);
    }

    public function filesForHumans(): string
    {
        return Number::format($this->files).' '.str('file')->plural($this->files);
    }

    public function modifiedForHumans(): ?string
    {
        return $this->modifiedAt?->inDisplayTimezone()->format('j M Y H:i');
    }

    public function scannedForHumans(): string
    {
        return 'Measured by the scan '.$this->scannedAt->diffForHumans().'.';
    }
}
