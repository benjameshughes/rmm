@props(['script', 'showPlatform' => true])

<div {{ $attributes->class('flex flex-wrap items-center gap-1.5') }}>
    @if($showPlatform)
        <flux:badge size="sm" color="zinc" data-script-platform>{{ $script->platform->label() }}</flux:badge>
    @endif
    @if($script->is_internal)
        <flux:badge size="sm" color="zinc" icon="lock-closed" data-internal-badge>Internal</flux:badge>
    @elseif($script->is_system)
        <flux:badge size="sm" color="blue">System</flux:badge>
    @else
        <flux:badge size="sm" color="violet">Custom</flux:badge>
    @endif
    @if($script->requires_admin)
        <flux:badge size="sm" color="amber" icon="shield-exclamation" data-script-admin>Admin</flux:badge>
    @endif
    <flux:badge size="sm" color="zinc" icon="clock">{{ $script->timeoutLabel() }}</flux:badge>
</div>
