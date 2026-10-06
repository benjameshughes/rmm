@props(['device', 'inventory' => null, 'collected' => null])

@if($inventory)
    <flux:card {{ $attributes->class('space-y-4') }} data-system-glance>
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:heading size="sm">This PC</flux:heading>
                @if($collected)
                    <flux:text size="xs" class="mt-1">{{ $collected }}</flux:text>
                @endif
            </div>
            <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="route('devices.system', $device)" wire:navigate>View all</flux:button>
        </div>

        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            @foreach($inventory->glance() as $label => $value)
                <div class="min-w-0" wire:key="glance-{{ Str::slug($label) }}">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="truncate font-medium text-zinc-800 dark:text-white" title="{{ $value }}">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        <flux:separator variant="subtle" />

        <div class="flex flex-wrap gap-2" data-security-strip>
            @foreach($inventory->security()->summary() as $check)
                <flux:badge size="sm" :color="$check->color" wire:key="security-{{ Str::slug($check->label) }}">{{ $check->label }} · {{ $check->value }}</flux:badge>
            @endforeach
        </div>
    </flux:card>
@endif
