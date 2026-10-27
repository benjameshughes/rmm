@props(['device', 'health'])

<div {{ $attributes->class('space-y-4') }} data-windows-health>
    <livewire:devices.system-inventory :device="$device" :key="'system-inventory-'.$device->id" />

    @if($health)
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-system-status>
            <x-inventory.status-tile :check="$health->systemErrorsStatus" href="#system-errors" :detail="$health->systemErrorsPeriod" />
            <x-inventory.status-tile :check="$health->deviceProblemsStatus" href="#device-problems" />
            <x-inventory.status-tile :check="$health->securityStatus" :href="route('devices.security', $device)" navigate />
            <x-inventory.status-tile :check="$health->restartStatus" :href="route('devices.apps', $device).'#updates'" :detail="$health->restartReasons" navigate />
        </div>

        <div @class(['grid items-start gap-6', 'lg:grid-cols-2' => $health->hasDeviceProblems])>
            <x-inventory.system-errors :events="$health->systemErrors" :more-events="$health->moreSystemErrors" :status="$health->systemErrorsStatus" :window="$health->systemErrorsWindow" />
            @if($health->hasDeviceProblems)
                <x-dashboard.section id="device-problems" class="scroll-mt-6" title="Device problems" description="Devices Windows says are not working, from Device Manager.">
                    <x-inventory.checks :checks="$health->deviceProblems" />
                </x-dashboard.section>
            @endif
        </div>
    @endif
</div>
