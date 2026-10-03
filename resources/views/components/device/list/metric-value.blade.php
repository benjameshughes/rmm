@props(['value' => null, 'color' => 'text-zinc-600 dark:text-zinc-300'])

<span {{ $attributes->class(['text-sm tabular-nums', $color => $value !== null, 'text-zinc-400 dark:text-zinc-500' => $value === null]) }}>{{ $value ?? '—' }}</span>
