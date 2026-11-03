@props(['title', 'count' => null, 'href' => null, 'linkLabel' => 'View all'])

<section {{ $attributes->class('min-w-0') }}>
    <div class="mb-3 flex items-baseline justify-between gap-3">
        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">
            {{ $title }}
            @if($count !== null)
                <span class="ms-1 font-normal tabular-nums text-zinc-400">{{ $count }}</span>
            @endif
            @isset($titleExtra)
                {{ $titleExtra }}
            @endisset
        </h2>
        @if($href)
            <a href="{{ $href }}" wire:navigate class="text-xs text-zinc-400 hover:text-zinc-800 dark:hover:text-white">{{ $linkLabel }} &rarr;</a>
        @endif
    </div>

    {{ $slot }}
</section>
