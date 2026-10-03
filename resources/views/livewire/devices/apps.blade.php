<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Apps">
    @if(filled($apps))
        <x-device.top-apps :apps="$apps" />
    @else
        <flux:card>
            <flux:text>This device has not reported app usage. Windows agents 0.6.0 and newer send the busiest apps with each report.</flux:text>
        </flux:card>
    @endif
</x-device.shell>
