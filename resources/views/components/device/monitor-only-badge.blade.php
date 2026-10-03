@props(['device', 'size' => 'sm'])

@if($device->isMonitorOnly)
    <flux:badge :size="$size" color="zinc" icon="eye" data-monitor-only {{ $attributes }}>Monitor only</flux:badge>
@endif
