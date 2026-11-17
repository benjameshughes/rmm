@props(['cards' => 4])

<flux:skeleton.group animate="shimmer" {{ $attributes->class('grid gap-6 lg:grid-cols-2') }}>
    @foreach (range(1, $cards) as $index)
        <flux:card wire:key="section-skeleton-{{ $index }}" class="space-y-3">
            <flux:skeleton.line size="lg" class="w-1/3" />
            <flux:skeleton.line />
            <flux:skeleton.line />
            <flux:skeleton.line class="w-2/3" />
        </flux:card>
    @endforeach
</flux:skeleton.group>
