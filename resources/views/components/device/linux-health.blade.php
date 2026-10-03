@props(['metric' => null])

@if($metric?->hasHealthReport)
    <flux:card {{ $attributes->class('space-y-4') }} data-linux-health>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:heading size="sm">Health</flux:heading>
            @if($metric->reboot_required)
                <flux:badge size="sm" color="amber" icon="arrow-path">Reboot required</flux:badge>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Services</flux:text>
                @if($metric->hasFailedUnits)
                    <ul class="space-y-1">
                        @foreach($metric->failed_units as $unit)
                            <li class="flex items-center gap-2" wire:key="failed-unit-{{ $unit }}">
                                <flux:icon name="x-circle" variant="micro" class="text-red-500" />
                                <span class="font-mono text-sm text-red-600 dark:text-red-400">{{ $unit }}</span>
                            </li>
                        @endforeach
                    </ul>
                @elseif($metric->failed_units !== null)
                    <div class="flex items-center gap-2">
                        <flux:icon name="check-circle" variant="micro" class="text-green-500" />
                        <flux:text size="sm" class="text-green-700 dark:text-green-400">All services running</flux:text>
                    </div>
                @else
                    <flux:text size="sm">Unknown: systemctl is not available</flux:text>
                @endif
            </div>

            <div class="space-y-2">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Updates</flux:text>
                @if($metric->pending_updates !== null)
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text size="sm" class="font-medium text-zinc-800 dark:text-white">{{ $metric->pendingUpdatesForHumans() }}</flux:text>
                        @if($metric->pending_security_updates)
                            <flux:badge size="sm" color="red" icon="shield-exclamation">{{ $metric->pendingSecurityUpdatesForHumans() }}</flux:badge>
                        @endif
                    </div>
                    <flux:text size="xs">
                        As of the box's last apt update
                        @if($metric->updates_checked_at)
                            &middot; checked {{ $metric->updates_checked_at->diffForHumans() }}
                        @endif
                    </flux:text>
                @else
                    <flux:text size="sm">Not reported</flux:text>
                @endif
            </div>
        </div>
    </flux:card>
@endif
