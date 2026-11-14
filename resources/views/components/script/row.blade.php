@props(['script', 'showPlatform' => true])

<div {{ $attributes->class('flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between') }}>
    <div class="min-w-0 space-y-1">
        <div class="font-medium text-zinc-800 dark:text-white">{{ $script->name }}</div>
        @if($script->description)
            <flux:text size="sm" class="line-clamp-2">{{ $script->description }}</flux:text>
        @endif
        <x-script.badges :script="$script" :show-platform="$showPlatform" class="pt-1" />
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
