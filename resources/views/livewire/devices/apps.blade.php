<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Apps">
    @if(filled($apps))
        <x-device.top-apps :apps="$apps" />
    @else
        <flux:card>
            <flux:text>This device has not reported app usage yet. Windows agents 0.6.0 and Linux agents 0.7.1 and newer send the busiest apps with each report.</flux:text>
        </flux:card>
    @endif
</x-device.shell>
