@props(['selection', 'noun'])

<div
    x-data="{
        get count() { return $wire.{{ $selection }}.length },
        get countLabel() { return this.count + ' ' + (this.count === 1 ? @js($noun) : @js(Str::plural($noun))) },
    }"
    x-show="count > 0"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-3"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-3"
    class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center px-4 lg:start-64"
    data-bulk-bar
>
    <div role="toolbar" aria-label="Bulk actions" class="dark pointer-events-auto flex max-w-full items-center gap-0.5 rounded-full bg-zinc-900 py-1.5 ps-3 pe-1.5 sm:ps-4 max-sm:[&_[data-flux-button]]:px-2 text-white shadow-xl shadow-black/20 ring-1 ring-white/10">
        <span class="pe-2 text-sm font-medium whitespace-nowrap tabular-nums"><span x-text="count"></span> selected</span>
        <div class="me-1 h-5 w-px bg-white/15"></div>

        {{ $slot }}

        <div class="mx-1 h-5 w-px bg-white/15"></div>
        <flux:button size="sm" variant="ghost" icon="x-mark" square class="rounded-full!" x-on:click="$wire.{{ $selection }} = []; $wire.clearSelection()" aria-label="Clear selection" />
    </div>
</div>
