@props(['metric' => null])

@if($metric?->hasAlertCounts)
    <flux:card {{ $attributes }}>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="sm">Netdata Alerts</flux:heading>
            <div class="flex gap-3">
                <div class="flex items-center gap-2">
                    <div class="size-2.5 rounded-full bg-green-500"></div>
                    <flux:text size="sm">{{ $metric->alerts_normal ?? 0 }} Normal</flux:text>
                </div>
                <div class="flex items-center gap-2">
                    <div class="size-2.5 rounded-full bg-amber-500"></div>
                    <flux:text size="sm">{{ $metric->alerts_warning ?? 0 }} Warning</flux:text>
                </div>
                <div class="flex items-center gap-2">
                    <div class="size-2.5 rounded-full bg-red-500"></div>
                    <flux:text size="sm">{{ $metric->alerts_critical ?? 0 }} Critical</flux:text>
                </div>
            </div>
        </div>
    </flux:card>
@endif
