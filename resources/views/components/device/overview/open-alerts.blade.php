@props(['alerts'])

@if($alerts->isNotEmpty())
    <flux:callout icon="bell-alert" color="red">
        <flux:callout.heading>{{ $alerts->count() }} open {{ Str::plural('alert', $alerts->count()) }}</flux:callout.heading>
        <flux:callout.text>
            <ul class="mt-1 space-y-1">
                @foreach($alerts as $alert)
                    <li class="flex flex-wrap items-center gap-2" wire:key="alert-{{ $alert->id }}">
                        <flux:badge size="sm" :color="$alert->severity->color()">{{ $alert->severity->label() }}</flux:badge>
                        <span>{{ $alert->conditionLabel() }}</span>
                        <span class="font-mono">{{ $alert->valueLabel() }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $alert->triggered_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </flux:callout.text>
        <x-slot name="actions">
            <flux:button size="sm" :href="route('alerts.index')" wire:navigate>View alerts</flux:button>
        </x-slot>
    </flux:callout>
@endif
