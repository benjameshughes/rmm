<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\DiskUsage\BuildDiskFolderRows;
use App\Actions\DiskUsage\FindDiskCulprits;
use App\Actions\DiskUsage\QueueDiskScan;
use App\DTOs\DiskUsage\DiskScan;
use App\DTOs\DiskUsage\DiskScanFile;
use App\DTOs\DiskUsage\DiskScanNode;
use App\Enums\DeviceTab;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceDiskScan;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What fills a Windows PC's drives, from its disk-usage scans: the folder
 * tree with growth since the previous scan, known space hogs, the biggest
 * files and extensions. The folder shown lives in the query string as an
 * index path, so drill-downs are bookmarkable.
 */
#[Layout('components.layouts.app')]
final class Storage extends Component
{
    public Device $device;

    #[Url(as: 'root')]
    public string $root = '';

    #[Url(as: 'node')]
    public string $node = '';

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device->load('latestInventory');
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

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

    public function render(BuildDiskFolderRows $buildRows, FindDiskCulprits $findCulprits): View
    {
        $canScan = $this->device->platform() === ScriptPlatform::Windows && ! $this->device->isMonitorOnly;
        $activeRoot = $canScan ? $this->activeRoot() : null;
        $scans = $this->scansOf($activeRoot);
        $latest = $scans->first();
        $scan = $latest?->scan();
        $previousScan = $scans->get(1)?->scan();
        $scanCommand = $canScan ? $this->inFlightScan() : null;
        $index = $scan?->tree->resolve($this->node) ?? 0;

        return view('livewire.devices.storage', [
            'canScan' => $canScan,
            'roots' => $canScan ? $this->roots() : collect(),
            'nodeUrl' => fn (string $indexPath): string => route('devices.storage', ['device' => $this->device, 'root' => $activeRoot, 'node' => $indexPath === '' ? null : $indexPath]),
            'latest' => $latest,
            'previous' => $scans->get(1),
            'scan' => $scan,
            'folder' => $scan?->tree->node($index),
            'breadcrumbs' => $scan === null ? collect() : $this->breadcrumbs($scan, $index),
            'rows' => $scan === null ? collect() : $buildRows($scan, $previousScan, $index),
            'culprits' => $scan === null ? collect() : $findCulprits($scan, $this->device->latestInventory?->snapshot()->users()->profileNamesBySid() ?? []),
            'topFiles' => $scan === null ? collect() : $this->topFiles($scan),
            'extensions' => $scan?->extensions->sortByDesc('allocated')->take(config('disk_usage.extensions_shown'))->values() ?? collect(),
            'scanCommand' => $scanCommand,
            'canScanFolder' => $scanCommand === null && $canScan && auth()->user()->can('runCommands', $this->device),
        ])->title(DeviceTab::Storage->pageTitle($this->device));
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
     * @return Collection<int, string> Newest scan first
     */
    private function roots(): Collection
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
    private function scansOf(?string $root): Collection
    {
        return $root === null ? collect() : $this->device->diskScans()->ofRoot($root)->latest('scanned_at')->latest('id')->limit(2)->get();
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
     * @return Collection<int, array{name: string, indexPath: string}>
     */
    private function breadcrumbs(DiskScan $scan, int $index): Collection
    {
        return $scan->tree->lineage($index)->map(fn (DiskScanNode $node): array => [
            'name' => $node->displayName(),
            'indexPath' => $scan->tree->indexPath($node->index),
        ]);
    }

    /**
     * @return Collection<int, array{path: string, size: string, modified: ?string}>
     */
    private function topFiles(DiskScan $scan): Collection
    {
        return $scan->topFiles
            ->sortByDesc(fn (DiskScanFile $file): int => $file->allocated)
            ->take(config('disk_usage.top_files_shown'))
            ->map(fn (DiskScanFile $file): array => [
                'path' => rtrim($scan->tree->path($file->parentIndex), '\\').'\\'.$file->name,
                'size' => Number::fileSize($file->allocated, precision: 1),
                'modified' => $file->modifiedAt?->inDisplayTimezone()->format('j M Y'),
            ])
            ->values();
    }
}
