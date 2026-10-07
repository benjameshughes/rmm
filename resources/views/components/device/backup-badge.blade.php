@props(['device', 'size' => 'sm'])

@php($state = $device->backupState())

@if($state->isWorthFlagging())
    <flux:badge :size="$size" :color="$state->color()" icon="cloud-arrow-up" data-backup-badge="{{ $state->value }}" {{ $attributes }}>
        Backup &middot; {{ $device->backupBadgeLabel($state) }}
    </flux:badge>
@endif
