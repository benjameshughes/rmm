@props(['runAs'])

<flux:badge size="sm" :color="$runAs->color()" {{ $attributes }}>{{ $runAs->label() }}</flux:badge>
