<x-device.shell :device="$device" :current="App\Enums\DeviceTab::Printers">
    @if(! $isWindows)
        <flux:card>
            <flux:text>Printers are only watched on Windows PCs.</flux:text>
        </flux:card>
    @elseif($reported === null)
        <x-dashboard.section title="Printers">
            <div class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-printers-empty>
                <flux:icon name="printer" class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>Agent hasn't reported printers yet</flux:heading>
                <flux:text class="max-w-md">Newer agents send every print queue and its jobs whenever the spooler changes and once a minute. Update the agent if this stays empty.</flux:text>
            </div>
        </x-dashboard.section>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:text size="sm">{{ $reported }}</flux:text>
            @can('runCommands', $device)
                <div class="flex flex-wrap items-center gap-2">
                    @if($commands->for(App\Enums\PrinterAction::InstallZebra))
                        <x-device.commands.busy :command="$commands->for(App\Enums\PrinterAction::InstallZebra)" />
                    @else
                        <flux:button size="sm" icon="tag" x-on:click="$dispatch('confirm-action', { heading: 'Install Zebra', message: {{ Js::from('Set the USB Zebra on '.$device->hostname.' up on the ZDesigner driver, '.($zebraTemplate ? 'apply the Zebra template' : 'with the driver defaults (no Zebra template yet)').', make it every user\'s default printer and remove every other Zebra queue, such as the Seagull ones, #2 and Copy 1?') }}, confirm: 'Install Zebra', action: () => $wire.installZebra() })" data-install-zebra>Install Zebra</flux:button>
                    @endif
                    @if($commands->for(App\Enums\PrinterAction::RestartSpooler))
                        <x-device.commands.busy :command="$commands->for(App\Enums\PrinterAction::RestartSpooler)" />
                    @else
                        <flux:button size="sm" icon="arrow-path" x-on:click="$dispatch('confirm-action', { heading: 'Restart print spooler', message: {{ Js::from('Restart the print spooler on '.$device->hostname.'? Anything printing right now starts again.') }}, confirm: 'Restart spooler', action: () => $wire.restartSpooler() })" data-restart-spooler>Restart spooler</flux:button>
                    @endif
                </div>
            @endcan
        </div>

        <flux:text size="sm" data-zebra-template>
            @if($zebraTemplate)
                Zebra template: {{ $zebraTemplate->summary() }}.
            @else
                No Zebra template yet. Install Zebra uses the driver's defaults until a ZDesigner printer is saved with Use as Zebra template.
            @endif
        </flux:text>

        <flux:error name="script" />
        <flux:error name="printer.PrinterName" />
        <flux:error name="printer.JobId" />

        @if($isSpoolerDown)
            <flux:callout variant="danger" icon="exclamation-triangle" heading="The print spooler is not running" data-spooler-down>
                <flux:callout.text>Nothing on this PC can print until it is back. The printers below are from the last time it answered.</flux:callout.text>
            </flux:callout>
        @endif

        @forelse($printers as $printer)
            <x-printers.card :printer="$printer" :queue="$printer->queue()" :device="$device" :commands="$commands" :jobs-shown="$jobsShown" wire:key="printer-{{ $printer->id }}" />
        @empty
            <flux:card>
                <flux:text>No printers on this PC, apart from software ones like Microsoft Print to PDF.</flux:text>
            </flux:card>
        @endforelse
    @endif
</x-device.shell>
