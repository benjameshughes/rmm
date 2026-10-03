@props(['package'])

@if($package->is_update_available)
    <flux:badge size="sm" color="amber" icon="arrow-up-circle" {{ $attributes }}>Update available{{ $package->latest_version ? ' → '.$package->latest_version : '' }}</flux:badge>
@endif
