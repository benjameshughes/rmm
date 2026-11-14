@props(['group'])

<section {{ $attributes->class('space-y-2') }} data-script-group="{{ $group->category->value }}">
    <div class="flex items-center gap-2 px-1">
        <flux:icon :name="$group->category->icon()" variant="mini" class="text-zinc-400 dark:text-zinc-500" />
        <flux:heading size="sm">{{ $group->category->label() }}</flux:heading>
        <flux:text size="sm">{{ $group->scripts->count() }}</flux:text>
    </div>

    <div class="divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
        {{ $slot }}
    </div>
</section>
