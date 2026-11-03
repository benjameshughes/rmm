@props(['segments'])

<div {{ $attributes->class('flex h-1.5 gap-px') }} data-hero-meter>
    @foreach($segments as $segment)
        <span class="flex-1 rounded-[1px] {{ $segment }}" wire:key="meter-{{ $loop->index }}"></span>
    @endforeach
</div>
