@props(['printer', 'queue', 'device', 'commands', 'jobsShown'])

<flux:card {{ $attributes->class('space-y-4') }} data-printer="{{ $printer->name }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" class="truncate">{{ $printer->name }}</flux:heading>
                <x-printers.flags :flags="$queue->statusFlags()" empty="Ready" />
                <flux:badge size="sm" color="zinc" icon="document-text">{{ $queue->jobsCount }} {{ Str::plural('job', $queue->jobsCount) }}</flux:badge>
            </div>
            <flux:text size="sm" class="truncate">{{ $queue->connectionForHumans() }}</flux:text>
        </div>

        @can('runCommands', $device)
            <div class="flex flex-wrap items-center gap-2">
                <x-device.commands.run-button :command="$commands->for(App\Enums\PrinterAction::PrintTestPage, $printer->name)" action="printTestPage({{ $printer->id }})" icon="document" variant="ghost">Test page</x-device.commands.run-button>
                @if($commands->for(App\Enums\PrinterAction::ClearQueue, $printer->name))
                    <x-device.commands.busy :command="$commands->for(App\Enums\PrinterAction::ClearQueue, $printer->name)" />
                @elseif($queue->jobsCount > 0)
                    <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$dispatch('confirm-action', { heading: 'Clear print queue', message: {{ Js::from('Remove all '.$queue->jobsCount.' '.Str::plural('job', $queue->jobsCount).' from '.$printer->name.' on '.$device->hostname.'?') }}, confirm: 'Clear queue', danger: true, action: () => $wire.clearQueue({{ $printer->id }}) })" data-clear-queue>Clear queue</flux:button>
                @endif
            </div>
        @endcan
    </div>

    @if($queue->hasProblem())
        <flux:callout variant="danger" icon="exclamation-triangle" data-printer-problem>
            <flux:callout.text>{{ $queue->problemSummary() }}</flux:callout.text>
        </flux:callout>
    @endif

    @if($queue->jobs->isEmpty())
        <flux:text size="sm">No jobs queued.</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Document</flux:table.column>
                <flux:table.column class="hidden md:table-cell">User</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column class="hidden lg:table-cell">Submitted</flux:table.column>
                <flux:table.column>Age</flux:table.column>
                <flux:table.column class="hidden sm:table-cell">Pages</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach($queue->jobs->take($jobsShown) as $job)
                    <x-printers.job-row :job="$job" :printer="$printer" :queue="$queue" :device="$device" :command="$commands->for(App\Enums\PrinterAction::CancelJob, $printer->name, $job->id)" wire:key="printer-{{ $printer->id }}-job-{{ $job->id }}" />
                @endforeach
            </flux:table.rows>
        </flux:table>

        @if($queue->jobsBeyond($jobsShown) > 0)
            <flux:text size="sm" data-more-jobs>And {{ $queue->jobsBeyond($jobsShown) }} more {{ Str::plural('job', $queue->jobsBeyond($jobsShown)) }}.</flux:text>
        @endif
    @endif
</flux:card>
