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
                @if($commands->for(App\Enums\PrinterAction::RestartSpooler))
                    <x-device.commands.busy :command="$commands->for(App\Enums\PrinterAction::RestartSpooler)" />
                @else
                    <flux:button size="sm" icon="arrow-path" x-on:click="$dispatch('confirm-action', { heading: 'Restart print spooler', message: {{ Js::from('Restart the print spooler on '.$device->hostname.'? Anything printing right now starts again.') }}, confirm: 'Restart spooler', action: () => $wire.restartSpooler() })" data-restart-spooler>Restart spooler</flux:button>
                @endif
            @endcan
        </div>

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
