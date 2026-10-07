@props(['flags', 'empty'])

<div {{ $attributes->class('flex flex-wrap items-center gap-1') }}>
    @forelse($flags as $flag)
        <flux:badge size="sm" :color="$flag->color()">{{ $flag->label() }}</flux:badge>
    @empty
        <flux:badge size="sm" color="zinc">{{ $empty }}</flux:badge>
    @endforelse
</div>
