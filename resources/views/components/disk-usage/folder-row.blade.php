@props(['row', 'href', 'canScanFolder' => false])

<flux:table.row {{ $attributes }} data-folder="{{ $row->name }}">
    <flux:table.cell class="max-w-xs">
        <div class="flex flex-wrap items-center gap-2">
            @if($row->canOpen)
                <a href="{{ $href }}" wire:click.prevent="open('{{ $row->indexPath }}')" class="truncate font-medium text-zinc-800 hover:underline dark:text-white" title="{{ $row->path }}" data-open-folder>{{ $row->name }}</a>
            @else
                <span class="truncate text-zinc-700 dark:text-zinc-300" title="{{ $row->path }}">{{ $row->name }}</span>
            @endif
            @foreach($row->badges as $flag)
                <flux:badge size="sm" :color="$flag->color()" :title="$flag->description()" wire:key="flag-{{ $row->index }}-{{ $flag->value }}" data-flag="{{ $flag->name }}">{{ $flag->label() }}</flux:badge>
            @endforeach
        </div>
        <x-device.usage-bar :percent="$row->percentOfParent" color="bg-blue-500" thin class="mt-1.5" />
    </flux:table.cell>
    <flux:table.cell align="end" class="tabular-nums">{{ $row->allocatedForHumans() }}</flux:table.cell>
    <flux:table.cell align="end" class="hidden tabular-nums sm:table-cell">{{ $row->percentForHumans() }}</flux:table.cell>
    <flux:table.cell align="end" class="hidden tabular-nums md:table-cell">{{ $row->filesForHumans() }}</flux:table.cell>
    <flux:table.cell align="end">
        @if($row->changeForHumans())
            <flux:badge size="sm" :color="$row->changeColor()" class="tabular-nums" data-change="{{ $row->changeForHumans() }}">{{ $row->changeForHumans() }}</flux:badge>
        @endif
    </flux:table.cell>
    <flux:table.cell align="end">
        @if($canScanFolder && $row->canScanDeeper)
            <flux:button size="xs" variant="ghost" icon="magnifying-glass" wire:click="scanFolder('{{ $row->indexPath }}')" data-scan-folder>Scan this folder</flux:button>
        @endif
    </flux:table.cell>
</flux:table.row>
