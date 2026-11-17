<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\DeletePath\GuardDeletablePath;
use App\Actions\DeletePath\QueueQuarantinePurge;
use App\Actions\DeletePath\QueueQuarantineRestore;
use App\Actions\DiskUsage\BuildDiskFolderRows;
use App\Actions\DiskUsage\FindDiskCulprits;
use App\Actions\DiskUsage\QueueDiskScan;
use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanFile;
use App\DTOs\DiskUsage\DiskScanNode;
use App\DTOs\DiskUsage\DiskScanTree;
use App\Enums\DeviceTab;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceDiskScan;
use App\Models\DeviceQuarantine;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What fills a device's disks, from its disk-usage scans: the folder
 * tree with growth since the previous scan, known space hogs, the biggest
 * files and extensions, and what sits in quarantine. Folders and files are
 * deleted through the shared DeletePath modal; Linux servers scan
 * themselves and can only be looked at. The folder shown lives in
 * the query string as an index path, so drill-downs are bookmarkable.
 */
#[Layout('components.layouts.app')]
#[Lazy]
final class Storage extends Component
{
    public Device $device;

    #[Url(as: 'root')]
    public string $root = '';

    #[Url(as: 'node')]
    public string $node = '';

    private BuildDiskFolderRows $buildRows;

    private FindDiskCulprits $findCulprits;

    private GuardDeletablePath $guard;

    public function boot(BuildDiskFolderRows $buildRows, FindDiskCulprits $findCulprits, GuardDeletablePath $guard): void
    {
        $this->buildRows = $buildRows;
        $this->findCulprits = $findCulprits;
        $this->guard = $guard;
    }

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestInventory');
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    #[On('command-queued')]
    public function refreshCommands(): void
    {
        $this->renderIsland(name: 'storage');
        $this->skipRender();
    }

    /**
     * A new scan landed. Keeps the folder on screen open in it by its path,
     * unless it was of another folder than the one being looked at.
     */
    #[On('echo-private:devices.{device.id},DiskScanStored')]
    public function scanLanded(): void
    {
        $landed = $this->device->diskScans()->latest('scanned_at')->latest('id')->first();

        if ($landed === null || ($this->root !== '' && $this->root !== $landed->root)) {
            return;
        }

        $previous = $this->scansOf($landed->root)->get(1)?->scan();
        $current = $landed->scan();
        $path = $previous?->tree->path($previous->tree->resolve($this->node));
        $index = $path === null ? null : $current?->tree->indexOfPath($path);

        $this->node = $index === null ? '' : $current->tree->indexPath($index);
    }

    public function updatedRoot(): void
    {
        $this->node = '';
    }

    public function open(string $indexPath): void
    {
        $this->node = $indexPath;
    }

    public function scanNow(QueueDiskScan $queue): void
    {
        $this->queueScan($queue, $this->activeRoot() ?? config('disk_usage.default_path'));
    }

    public function scanFolder(string $indexPath, QueueDiskScan $queue): void
    {
        $scan = $this->latestScan()?->scan();

        abort_if($scan === null, 404);

        $this->queueScan($queue, $scan->tree->path($scan->tree->resolve($indexPath)));
    }

    public function purgeQuarantine(int $quarantineId, QueueQuarantinePurge $queuePurge): void
    {
        $this->authorize('deletePaths', $this->device);

        $quarantine = $this->device->quarantines()->findOrFail($quarantineId);
        $command = $queuePurge($this->device, auth()->user(), $quarantine);
        $this->dispatch('command-queued');

        Flux::toast(
            text: $command === null ? 'A purge of it is already queued or running.' : 'The drive is rescanned once the space comes back.',
            heading: "Purge of {$quarantine->path} queued for {$this->device->hostname}",
            variant: $command === null ? 'warning' : 'success',
        );
    }

    public function restoreQuarantine(int $quarantineId, QueueQuarantineRestore $queueRestore): void
    {
        $this->authorize('deletePaths', $this->device);

        $quarantine = $this->device->quarantines()->findOrFail($quarantineId)->setRelation('device', $this->device);
        $command = $queueRestore($quarantine, auth()->user());
        $this->dispatch('command-queued');

        Flux::toast(
            text: $command === null ? 'A restore of it is already queued or running.' : 'It goes back unless something new is already at that path.',
            heading: "Restore of {$quarantine->path} queued for {$this->device->hostname}",
            variant: $command === null ? 'warning' : 'success',
        );
    }

    private function queueScan(QueueDiskScan $queue, string $path): void
    {
        $this->authorize('runCommands', $this->device);

        $command = $queue($this->device, auth()->user(), $path);
        $this->dispatch('command-queued');

        Flux::toast(
            text: $command === null ? 'A scan is already queued or running on this PC.' : 'This tab updates by itself once the scan lands.',
            heading: "Disk scan of {$path} queued for {$this->device->hostname}",
            variant: $command === null ? 'warning' : 'success',
        );
    }

    public function placeholder(): View
    {
        return view('livewire.devices.placeholders.storage');
    }

    public function render(): View
    {
        return view('livewire.devices.storage')->title(DeviceTab::Storage->pageTitle($this->device));
    }

    #[Computed]
    public function canScan(): bool
    {
        return $this->device->platform() === ScriptPlatform::Windows && ! $this->device->isMonitorOnly;
    }

    /**
     * @return Collection<int, string> Newest scan first
     */
    #[Computed]
    public function roots(): Collection
    {
        return $this->device->diskScans()
            ->select('root')
            ->groupBy('root')
            ->orderByRaw('max(scanned_at) desc')
            ->pluck('root');
    }

    /**
     * @return Collection<int, DeviceDiskScan> Newest first
     */
    #[Computed]
    public function scans(): Collection
    {
        return $this->scansOf($this->activeRoot());
    }

    #[Computed]
    public function latest(): ?DeviceDiskScan
    {
        return $this->scans->first();
    }

    #[Computed]
    public function previous(): ?DeviceDiskScan
    {
        return $this->scans->get(1);
    }

    #[Computed]
    public function scan(): ?DiskScan
    {
        return $this->withMountVolume($this->latest?->scan());
    }

    #[Computed]
    public function scanCommand(): ?DeviceCommand
    {
        return $this->canScan ? $this->inFlightScan() : null;
    }

    #[Computed]
    public function folder(): ?DiskScanNode
    {
        return $this->scan?->tree->node($this->folderIndex());
    }

    /**
     * @return Collection<int, array{name: string, indexPath: string}>
     */
    #[Computed]
    public function breadcrumbs(): Collection
    {
        return $this->scan === null ? collect() : $this->breadcrumbsOf($this->scan, $this->folderIndex());
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->scan === null ? collect() : ($this->buildRows)($this->scan, $this->previous?->scan(), $this->folderIndex());
    }

    #[Computed]
    public function culprits(): Collection
    {
        return $this->scan === null ? collect() : ($this->findCulprits)($this->scan, $this->device->latestInventory?->snapshot()->users()->profileNamesBySid() ?? []);
    }

    /**
     * @return Collection<int, array{path: string, size: string, modified: ?string, deleting: ?DeviceCommand}>
     */
    #[Computed]
    public function topFiles(): Collection
    {
        return $this->scan === null ? collect() : $this->topFilesOf($this->scan, $this->deleting);
    }

    #[Computed]
    public function extensions(): Collection
    {
        return $this->scan?->extensions->sortByDesc('allocated')->take(config('disk_usage.extensions_shown'))->values() ?? collect();
    }

    #[Computed]
    public function canScanFolder(): bool
    {
        return $this->scanCommand === null && $this->canScan && auth()->user()->can('runCommands', $this->device);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return $this->canScan && auth()->user()->can('deletePaths', $this->device);
    }

    /**
     * In-flight deletes by path.
     *
     * @return Collection<string, DeviceCommand>
     */
    #[Computed]
    public function deleting(): Collection
    {
        return $this->removals
            ->filter(fn (DeviceCommand $command): bool => $command->script->slug === config('devices.delete_path.slug'))
            ->keyBy(fn (DeviceCommand $command): string => (string) ($command->parameters['Path'] ?? ''));
    }

    /**
     * @return Collection<int, array{quarantine: DeviceQuarantine, command: ?DeviceCommand}>
     */
    #[Computed]
    public function quarantineItems(): Collection
    {
        return $this->canScan ? $this->quarantineItemsWith($this->removals) : collect();
    }

    /**
     * Deletes, purges and restores queued or running, with their scripts.
     *
     * @return Collection<int, DeviceCommand>
     */
    #[Computed]
    public function removals(): Collection
    {
        return $this->canScan ? $this->inFlightRemovals() : collect();
    }

    protected function nodeUrl(string $indexPath): string
    {
        return route('devices.storage', ['device' => $this->device, 'root' => $this->activeRoot(), 'node' => $indexPath === '' ? null : $indexPath]);
    }

    protected function isDeletable(string $path): bool
    {
        return $this->canDelete && $this->guard->refusal($path) === null;
    }

    private function folderIndex(): int
    {
        return $this->scan?->tree->resolve($this->node) ?? 0;
    }

    /**
     * The folder chosen in the query string, else the one scanned most recently.
     */
    private function activeRoot(): ?string
    {
        $roots = $this->roots();

        return $roots->contains($this->root) ? $this->root : $roots->first();
    }

    /**
     * @return Collection<int, DeviceDiskScan> Newest first
     */
    private function scansOf(?string $root): Collection
    {
        return $root === null ? collect() : $this->device->diskScans()->ofRoot($root)->latest('scanned_at')->latest('id')->limit(2)->get();
    }

    /**
     * A Linux mount point scanned without volume figures borrows them from
     * the latest disk metrics of that mount point.
     */
    private function withMountVolume(?DiskScan $scan): ?DiskScan
    {
        if ($scan === null || $scan->volumeTotal !== null || ! Str::startsWith($scan->root, '/')) {
            return $scan;
        }

        $disk = $this->device->latestMetric?->diskMetrics->firstWhere('mount_point', $scan->root);

        return $disk?->total_gb === null || $disk->available_gb === null
            ? $scan
            : $scan->withVolume(total: (int) ($disk->total_gb * 1024 ** 3), free: (int) ($disk->available_gb * 1024 ** 3));
    }

    private function latestScan(): ?DeviceDiskScan
    {
        return $this->scansOf($this->activeRoot())->first();
    }

    private function inFlightScan(): ?DeviceCommand
    {
        return $this->device->inFlightCommands()
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->where('slug', config('disk_usage.slug')))
            ->latest('id')
            ->first();
    }

    /**
     * Deletes, purges and restores queued or running, with their scripts.
     *
     * @return Collection<int, DeviceCommand>
     */
    private function inFlightRemovals(): Collection
    {
        $slugs = config('devices.delete_path');

        return $this->device->inFlightCommands()
            ->with('script')
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->system()->whereIn('slug', [$slugs['slug'], $slugs['purge_slug'], $slugs['restore_slug']]))
            ->latest('id')
            ->get();
    }

    /**
     * Everything still in quarantine, newest first, each with the purge or restore in flight for it.
     *
     * @param  Collection<int, DeviceCommand>  $removals
     * @return Collection<int, array{quarantine: DeviceQuarantine, command: ?DeviceCommand}>
     */
    private function quarantineItemsWith(Collection $removals): Collection
    {
        return $this->device->quarantines()->held()->latest('quarantined_at')->get()
            ->map(fn (DeviceQuarantine $quarantine): array => [
                'quarantine' => $quarantine,
                'command' => $removals->first(fn (DeviceCommand $command): bool => ($command->parameters['Folder'] ?? null) === $quarantine->folder),
            ]);
    }

    /**
     * @return Collection<int, array{name: string, indexPath: string}>
     */
    private function breadcrumbsOf(DiskScan $scan, int $index): Collection
    {
        return $scan->tree->lineage($index)->map(fn (DiskScanNode $node): array => [
            'name' => $node->displayName(),
            'indexPath' => $scan->tree->indexPath($node->index),
        ]);
    }

    /**
     * @param  Collection<string, DeviceCommand>  $deleting  In-flight deletes by path
     * @return Collection<int, array{path: string, size: string, modified: ?string, deleting: ?DeviceCommand}>
     */
    private function topFilesOf(DiskScan $scan, Collection $deleting): Collection
    {
        return $scan->topFiles
            ->sortByDesc(fn (DiskScanFile $file): int => $file->allocated)
            ->take(config('disk_usage.top_files_shown'))
            ->map(function (DiskScanFile $file) use ($scan, $deleting): array {
                $path = DiskScanTree::join($scan->tree->path($file->parentIndex), $file->name);

                return [
                    'path' => $path,
                    'size' => Number::fileSize($file->allocated, precision: 1),
                    'modified' => $file->modifiedAt?->inDisplayTimezone()->format('j M Y'),
                    'deleting' => $deleting->get($path),
                ];
            })
            ->values();
    }
}
