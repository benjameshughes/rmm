<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Storage">
    @island(name: 'storage', always: true)
        @if(! $this->canScan && $this->scan === null)
            <flux:card data-storage-unscannable>
                <flux:text>Disk scans run on Windows PCs that accept commands, and Linux servers scan their own disks every few hours. None has come in from this device yet.</flux:text>
            </flux:card>
        @elseif($this->scan === null)
            <x-dashboard.section title="Storage">
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-storage-empty>
                    <flux:icon name="circle-stack" class="size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:heading>No disk scan yet</flux:heading>
                    <flux:text class="max-w-md">A disk scan measures every folder on the drive, finds the biggest files and the usual space hogs, and changes nothing. It runs by itself when a disk space alert opens.</flux:text>
                    @can('runCommands', $device)
                        <x-device.commands.run-button :command="$this->scanCommand" action="scanNow" icon="magnifying-glass" variant="primary" data-scan-now>Scan now</x-device.commands.run-button>
                    @endcan
                    <flux:error name="scan" />
                </div>
            </x-dashboard.section>

            @if($this->quarantineItems->isNotEmpty())
                <x-disk-usage.quarantine :items="$this->quarantineItems" :can-change="$this->canDelete" />
            @endif
        @else
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    @if($this->roots->count() > 1)
                        <flux:select size="sm" wire:model.live="root" class="max-w-xs" data-root-select>
                            @foreach($this->roots as $scannedRoot)
                                <flux:select.option :value="$scannedRoot" wire:key="root-{{ $loop->index }}">{{ $scannedRoot }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:text size="sm" data-scanned-at>
                        {{ $this->latest->scannedForHumans() }}
                        @if($this->previous) Compared with the scan {{ $this->previous->scanned_at->diffForHumans() }}. @endif
                        @if($this->scan->errorCount > 0) {{ Number::format($this->scan->errorCount) }} {{ Str::plural('folder', $this->scan->errorCount) }} could not be read. @endif
                    </flux:text>
                </div>
                @can('runCommands', $device)
                    <x-device.commands.run-button :command="$this->scanCommand" action="scanNow" icon="arrow-path" data-scan-now>Scan now</x-device.commands.run-button>
                @endcan
            </div>

            <flux:error name="scan" />

            @if($this->scan->isDriveRoot() && $this->scan->volumeTotal)
                <x-disk-usage.volume-bar :scan="$this->scan" />
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <flux:card class="space-y-4 lg:col-span-2">
                    <flux:breadcrumbs data-storage-breadcrumbs>
                        @foreach($this->breadcrumbs as $crumb)
                            @if($loop->last)
                                <flux:breadcrumbs.item wire:key="crumb-{{ $loop->index }}">{{ $crumb['name'] }}</flux:breadcrumbs.item>
                            @else
                                <flux:breadcrumbs.item :href="$this->nodeUrl($crumb['indexPath'])" wire:click.prevent="open('{{ $crumb['indexPath'] }}')" wire:key="crumb-{{ $loop->index }}">{{ $crumb['name'] }}</flux:breadcrumbs.item>
                            @endif
                        @endforeach
                    </flux:breadcrumbs>

                    <flux:text size="sm">{{ Number::fileSize($this->folder->allocated, precision: 1) }} in {{ Number::format($this->folder->files) }} files</flux:text>

                    @if($this->rows->isEmpty())
                        <flux:text size="sm" data-folder-empty>No folders below this one in the scan.</flux:text>
                    @else
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Folder</flux:table.column>
                                <flux:table.column align="end">Size</flux:table.column>
                                <flux:table.column align="end" class="hidden sm:table-cell">Of folder</flux:table.column>
                                <flux:table.column align="end" class="hidden md:table-cell">Files</flux:table.column>
                                <flux:table.column align="end">Since last scan</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($this->rows as $row)
                                    <x-disk-usage.folder-row :row="$row" :href="$this->nodeUrl($row->indexPath)" :can-scan-folder="$this->canScanFolder" :can-delete="$this->isDeletable($row->path)" :deleting="$this->deleting->get($row->path)" wire:key="folder-{{ $this->latest->id }}-{{ $row->index }}" />
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @endif
                </flux:card>

                <x-disk-usage.culprits :culprits="$this->culprits" :node-url="$this->nodeUrl(...)" />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <x-dashboard.section title="Largest files" class="lg:col-span-2">
                    @if($this->topFiles->isEmpty())
                        <flux:text size="sm">No files reported.</flux:text>
                    @else
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>File</flux:table.column>
                                <flux:table.column align="end">Size</flux:table.column>
                                <flux:table.column align="end" class="hidden sm:table-cell">Modified</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($this->topFiles as $file)
                                    <flux:table.row :key="'file-'.$loop->index" data-top-file>
                                        <flux:table.cell class="max-w-md truncate font-mono text-xs" :title="$file['path']">{{ $file['path'] }}</flux:table.cell>
                                        <flux:table.cell align="end" class="tabular-nums">{{ $file['size'] }}</flux:table.cell>
                                        <flux:table.cell align="end" class="hidden sm:table-cell">{{ $file['modified'] ?? '—' }}</flux:table.cell>
                                        <flux:table.cell align="end">
                                            <x-disk-usage.file-actions :path="$file['path']" :deleting="$file['deleting']" :can-delete="$this->isDeletable($file['path'])" />
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="By file type">
                    @if($this->extensions->isEmpty())
                        <flux:text size="sm">No file types reported.</flux:text>
                    @else
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach($this->extensions as $extension)
                                <div class="flex items-center justify-between gap-3 py-2" wire:key="extension-{{ $loop->index }}" data-extension="{{ $extension->extension }}">
                                    <span class="font-mono text-sm text-zinc-800 dark:text-white">{{ $extension->extension }}</span>
                                    <flux:text size="sm" class="tabular-nums">{{ Number::fileSize($extension->allocated, precision: 1) }} &middot; {{ Number::format($extension->count) }} {{ Str::plural('file', $extension->count) }}</flux:text>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>

            @if($this->quarantineItems->isNotEmpty())
                <x-disk-usage.quarantine :items="$this->quarantineItems" :can-change="$this->canDelete" />
            @endif
        @endif
    @endisland
</x-device.shell>
