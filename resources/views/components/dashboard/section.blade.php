@props(['title', 'href' => null, 'linkLabel' => 'View all', 'description' => null])

<flux:card {{ $attributes->class('space-y-4') }}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if($description)
                <flux:text size="sm" class="mt-1">{{ $description }}</flux:text>
            @endif
        </div>
        @if($href)
            <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="$href" wire:navigate>{{ $linkLabel }}</flux:button>
        @endif
    </div>

    {{ $slot }}
</flux:card>
