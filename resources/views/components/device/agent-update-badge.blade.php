@props(['device', 'latestVersion'])

@if($device->isAgentOutdated($latestVersion))
    <flux:badge size="sm" color="amber" icon="arrow-up-circle" {{ $attributes }}>
        Update available {{ $device->agent_version }} &rarr; {{ $latestVersion }}
    </flux:badge>
@endif
