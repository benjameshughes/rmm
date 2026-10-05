@props(['items', 'name', 'empty' => null])

@if(filled($items))
    <div {{ $attributes->class('flex flex-wrap gap-1') }}>
        @foreach($items as $item)
            <flux:badge size="sm" wire:key="{{ $name }}-{{ $loop->index }}">{{ $item }}</flux:badge>
        @endforeach
    </div>
@elseif($empty)
    <flux:text size="sm">{{ $empty }}</flux:text>
@endif
