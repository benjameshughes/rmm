@props(['checks', 'empty' => 'Nothing reported.'])

@if($checks->isEmpty())
    <flux:text size="sm">{{ $empty }}</flux:text>
@else
    <dl {{ $attributes->class('space-y-3 text-sm') }}>
        @foreach($checks as $check)
            @unless($loop->first)
                <flux:separator variant="subtle" />
            @endunless
            <div class="flex items-center justify-between gap-4">
                <dt class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ $check->label }}</dt>
                <dd class="text-right"><flux:badge size="sm" :color="$check->color">{{ $check->value }}</flux:badge></dd>
            </div>
        @endforeach
    </dl>
@endif
