@props(['facts', 'empty' => 'Nothing reported.'])

@if(blank($facts))
    <flux:text size="sm">{{ $empty }}</flux:text>
@else
    <dl {{ $attributes->class('space-y-3 text-sm') }}>
        @foreach($facts as $label => $value)
            @unless($loop->first)
                <flux:separator variant="subtle" />
            @endunless
            <div class="flex justify-between gap-4">
                <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                <dd class="text-right font-medium">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
