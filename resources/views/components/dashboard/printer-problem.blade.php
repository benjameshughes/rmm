@props(['attention'])

<div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-1 py-2') }} data-printer-problem-row>
    <flux:icon name="printer" class="size-5 shrink-0 text-red-500" />
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-2">
            <a href="{{ route('devices.printers', $attention->device) }}" wire:navigate class="truncate text-sm font-medium text-zinc-800 hover:underline dark:text-white">{{ $attention->device->hostname }}</a>
            @if($attention->printerName)
                <flux:text size="sm" class="truncate">{{ $attention->printerName }}</flux:text>
            @endif
        </div>
        <flux:text size="xs" class="truncate text-red-600 dark:text-red-400">{{ $attention->problem }}</flux:text>
    </div>
    <flux:text size="xs">for {{ $attention->sinceForHumans() }}</flux:text>
</div>
