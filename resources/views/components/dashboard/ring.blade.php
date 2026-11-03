@props(['ring'])

{{-- pathLength 100 makes each arc's length and offset plain percentages of the circle. --}}
<svg viewBox="0 0 36 36" {{ $attributes->class('-rotate-90') }} role="img" aria-label="{{ $ring->tooltip() }}">
    <circle cx="18" cy="18" r="16" fill="none" stroke-width="3" class="stroke-zinc-100 dark:stroke-zinc-800" />
    @foreach($ring->arcs() as $arc)
        <circle cx="18" cy="18" r="16" fill="none" stroke-width="3" pathLength="100" stroke-dasharray="{{ $arc['length'] }} 100" stroke-dashoffset="{{ $arc['offset'] }}" class="{{ $arc['segment']->strokeClass }}" wire:key="arc-{{ $loop->index }}" />
    @endforeach
</svg>
