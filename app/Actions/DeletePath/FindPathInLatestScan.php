<?php

declare(strict_types=1);

namespace App\Actions\DeletePath;

use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanFile;
use App\DTOs\DiskUsage\ScannedPath;
use App\Enums\DiskNodeFlag;
use App\Enums\PathKind;
use App\Models\Device;
use App\Models\DeviceDiskScan;
use Illuminate\Support\Str;

/**
 * What the newest scan covering a path measured for it: a folder in its
 * tree or one of its largest files. Null when no scan reached that far.
 * Only the newest covering scan's JSON is loaded.
 */
final class FindPathInLatestScan
{
    public function __invoke(Device $device, string $path): ?ScannedPath
    {
        $covering = $device->diskScans()
            ->select(['id', 'root', 'scanned_at'])
            ->latest('scanned_at')
            ->latest('id')
            ->get()
            ->first(fn (DeviceDiskScan $scan): bool => $this->covers($scan->root, $path));

        $scan = $covering === null ? null : DeviceDiskScan::query()->find($covering->id)?->scan();

        return $scan === null ? null : $this->folder($scan, $path, $covering) ?? $this->file($scan, $path, $covering);
    }

    private function covers(string $root, string $path): bool
    {
        $root = Str::lower(rtrim($root, '\\'));
        $path = Str::lower($path);

        return $path === $root || Str::startsWith($path, $root.'\\');
    }

    private function folder(DiskScan $scan, string $path, DeviceDiskScan $covering): ?ScannedPath
    {
        $index = $scan->tree->indexOfPath($path);
        $node = $index === null ? null : $scan->tree->node($index);

        return $node === null || $node->has(DiskNodeFlag::Other) ? null : new ScannedPath(
            kind: PathKind::Folder,
            allocated: $node->allocated,
            files: $node->files,
            modifiedAt: null,
            scannedAt: $covering->scanned_at,
        );
    }

    private function file(DiskScan $scan, string $path, DeviceDiskScan $covering): ?ScannedPath
    {
        $file = $scan->topFiles->first(fn (DiskScanFile $file): bool => Str::lower(rtrim($scan->tree->path($file->parentIndex), '\\').'\\'.$file->name) === Str::lower($path));

        return $file === null ? null : new ScannedPath(
            kind: PathKind::File,
            allocated: $file->allocated,
            files: 1,
            modifiedAt: $file->modifiedAt,
            scannedAt: $covering->scanned_at,
        );
    }
}
