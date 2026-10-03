@props(['disk' => null])

<span title="{{ $disk ? $disk['name'].' · '.$disk['freeForHumans'] : '' }}" {{ $attributes }}>
    <x-device.list.metric-value :value="$disk['usedRoundedForHumans'] ?? null" :color="$disk['usedTextColor'] ?? 'text-zinc-600 dark:text-zinc-300'" />
</span>
