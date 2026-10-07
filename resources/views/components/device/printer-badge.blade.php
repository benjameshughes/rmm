@props(['label' => null, 'size' => 'sm'])

@if($label)
    <flux:badge :size="$size" color="red" icon="printer" data-printer-problem-badge {{ $attributes }}>Printer &middot; {{ $label }}</flux:badge>
@endif
