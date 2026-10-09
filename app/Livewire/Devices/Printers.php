<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Printer\QueuePrinterAction;
use App\DTOs\Printers\PrinterCommands;
use App\DTOs\Printers\PrintJob;
use App\Enums\DeviceTab;
use App\Enums\PrinterAction;
use App\Enums\ScriptPlatform;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DevicePrinter;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Every print queue on the PC and its jobs, from the agent's latest snapshot,
 * with the print queue scripts one click away.
 */
#[Layout('components.layouts.app')]
final class Printers extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('echo-private:devices.{device.id},PrintersReported')]
    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    #[On('echo-private:devices.{device.id},CommandUpdated')]
    #[On('echo-private:devices.{device.id},CommandProgressed')]
    #[On('command-queued')]
    public function refreshCommands(): void {}

    public function clearQueue(int $printerId, QueuePrinterAction $action): void
    {
        $this->runOnPrinter(PrinterAction::ClearQueue, $printerId, $action);
    }

    public function printTestPage(int $printerId, QueuePrinterAction $action): void
    {
        $this->runOnPrinter(PrinterAction::PrintTestPage, $printerId, $action);
    }

    /**
     * Only a job in the printer's latest snapshot can be cancelled from here.
     */
    public function cancelJob(int $printerId, int $jobId, QueuePrinterAction $action): void
    {
        $this->authorize('runCommands', $this->device);

        $printer = $this->findPrinter($printerId);
        abort_unless($printer->queue()->jobs->contains(fn (PrintJob $job): bool => $job->id === $jobId), 404);

        $action(PrinterAction::CancelJob, $this->device, auth()->user(), printerName: $printer->name, jobId: $jobId);
        $this->announceQueued(PrinterAction::CancelJob, "Job {$jobId} on {$printer->name}.");
    }

    public function restartSpooler(QueuePrinterAction $action): void
    {
        $this->authorize('runCommands', $this->device);

        $action(PrinterAction::RestartSpooler, $this->device, auth()->user());
        $this->announceQueued(PrinterAction::RestartSpooler, "On {$this->device->hostname}.");
    }

    private function runOnPrinter(PrinterAction $printerAction, int $printerId, QueuePrinterAction $action): void
    {
        $this->authorize('runCommands', $this->device);

        $printer = $this->findPrinter($printerId);
        $action($printerAction, $this->device, auth()->user(), printerName: $printer->name);
        $this->announceQueued($printerAction, "{$printer->name} on {$this->device->hostname}.");
    }

    private function findPrinter(int $printerId): DevicePrinter
    {
        return $this->device->printers()->findOrFail($printerId);
    }

    private function announceQueued(PrinterAction $printerAction, string $text): void
    {
        $this->dispatch('command-queued');

        Flux::toast(text: "{$text} This tab updates by itself once the agent has run it.", heading: $printerAction->queuedHeading(), variant: 'success');
    }

    public function render(): View
    {
        $isWindows = $this->device->platform() === ScriptPlatform::Windows;

        return view('livewire.devices.printers', [
            'isWindows' => $isWindows,
            'printers' => $isWindows
                ? $this->device->printers()->get()->sortBy(fn (DevicePrinter $printer): array => [$printer->queue()->problemRank(), $printer->name])->values()
                : collect(),
            'reported' => $this->device->printersReportedForHumans(),
            'isSpoolerDown' => $this->device->spooler_down_since !== null,
            'commands' => new PrinterCommands($isWindows ? $this->inFlightPrinterCommands() : collect()),
            'jobsShown' => config('printers.jobs_shown'),
        ])->title(DeviceTab::Printers->pageTitle($this->device));
    }

    /** @return Collection<int, DeviceCommand> */
    private function inFlightPrinterCommands(): Collection
    {
        return $this->device->inFlightCommands()
            ->with('script')
            ->whereRelation('script', fn (Builder $scriptQuery): Builder => $scriptQuery->whereIn('slug', collect(PrinterAction::cases())->map(fn (PrinterAction $action): string => $action->value)))
            ->oldest('id')
            ->get();
    }
}
