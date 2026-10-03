@props(['label', 'color', 'size' => null])

<flux:badge :color="$color" :size="$size" icon="signal" data-device-status="{{ $label }}" {{ $attributes }}>{{ $label }}</flux:badge>
