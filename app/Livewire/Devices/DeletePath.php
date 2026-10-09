<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\DeletePath\FindPathInLatestScan;
use App\Actions\DeletePath\GuardDeletablePath;
use App\Actions\DeletePath\QueueDeletePath;
use App\DTOs\DiskUsage\ScannedPath;
use App\Enums\DeleteMode;
use App\Enums\PathKind;
use App\Models\Device;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The one confirmation for deleting a file or folder on a PC, opened from
 * the Storage tab with a path from the scan or from the header with a path
 * typed in. Shows what the latest scan measured, asks delete or
 * quarantine, and wants the name typed when it is big or unmeasured.
 */
final class DeletePath extends Component
{
    #[Locked]
    public Device $device;

    public bool $showModal = false;

    #[Locked]
    public bool $isPathFixed = false;

    public string $path = '';

    #[Locked]
    public string $kind = 'any';

    public string $mode = 'delete';

    public string $confirmation = '';

    public function mount(Device $device): void
    {
        $this->device = $device;
    }

    /**
     * Opens the modal for a path from a scan, or blank for one typed in.
     */
    #[On('delete-path')]
    public function open(string $path = '', string $kind = 'any'): void
    {
        $this->authorize('deletePaths', $this->device);

        $this->resetValidation();
        $this->path = $path;
        $this->isPathFixed = $path !== '';
        $this->kind = PathKind::tryFrom($kind)?->value ?? PathKind::Any->value;
        $this->mode = DeleteMode::Delete->value;
        $this->confirmation = '';
        $this->showModal = true;
    }

    public function updatedPath(): void
    {
        $this->confirmation = '';
    }

    public function confirm(QueueDeletePath $queueDeletePath, GuardDeletablePath $guard, FindPathInLatestScan $findPath): void
    {
        $this->authorize('deletePaths', $this->device);

        $requiresTyping = $this->requiresTyping($this->target($guard, $findPath));

        $this->validate([
            'path' => ['required', 'string', 'max:'.config('scripts.parameters.max_value_length'), $this->guardRule($guard)],
            'mode' => ['required', Rule::enum(DeleteMode::class)],
            'confirmation' => [$requiresTyping ? 'required' : 'nullable', $this->confirmationRule($requiresTyping)],
        ], [
            'path.required' => 'Type the full path of a file or folder, such as C:\\Veeam Backup Cache.',
            'path.max' => 'Paths may not be longer than :max characters.',
            'mode.required' => 'Choose delete or quarantine.',
            'mode.enum' => 'Choose delete or quarantine.',
            'confirmation.required' => 'Type the name to confirm.',
        ]);

        $command = $queueDeletePath($this->device, auth()->user(), $this->path, DeleteMode::from($this->mode), PathKind::from($this->kind));

        $this->showModal = false;
        $this->dispatch('command-queued');

        Flux::toast(
            text: $command === null ? 'A delete of this path is already queued or running.' : 'The Storage tab rescans the drive once it is done.',
            heading: $command === null ? "Already deleting {$this->leafName()}" : "{$this->deleteMode()->queuedLabel()} {$this->leafName()} on {$this->device->hostname}",
            variant: $command === null ? 'warning' : 'success',
        );
    }

    public function render(GuardDeletablePath $guard, FindPathInLatestScan $findPath): View
    {
        $target = $this->showModal ? $this->target($guard, $findPath) : null;

        return view('livewire.devices.delete-path', [
            'target' => $target,
            'modes' => DeleteMode::cases(),
            'requiresTyping' => $this->showModal && $this->requiresTyping($target),
            'leafName' => $this->leafName(),
            'confirmLabel' => $this->deleteMode()->confirmLabel($target?->sizeForHumans() ?? $this->leafName(), $this->device->hostname),
        ]);
    }

    /**
     * What the newest scan covering the path measured, when one did. Nothing
     * is looked up for a path the guard rails refuse.
     */
    private function target(GuardDeletablePath $guard, FindPathInLatestScan $findPath): ?ScannedPath
    {
        return $this->path === '' || $guard->refusal($this->path) !== null
            ? null
            : $findPath($this->device, $guard->normalise($this->path));
    }

    /**
     * Big deletes and ones no scan has measured need the name typed.
     */
    private function requiresTyping(?ScannedPath $target): bool
    {
        return $target === null || $target->allocated > config('devices.delete_path.confirm_typing_over_bytes');
    }

    private function leafName(): string
    {
        $name = Str::afterLast(rtrim(str_replace('/', '\\', trim($this->path)), '\\'), '\\');

        return $name === '' ? 'this path' : $name;
    }

    private function deleteMode(): DeleteMode
    {
        return DeleteMode::tryFrom($this->mode) ?? DeleteMode::Delete;
    }

    private function guardRule(GuardDeletablePath $guard): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($guard): void {
            $refusal = $guard->refusal((string) $value);

            if ($refusal !== null) {
                $fail($refusal->getMessage());
            }
        };
    }

    private function confirmationRule(bool $requiresTyping): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($requiresTyping): void {
            if ($requiresTyping && Str::lower(trim((string) $value)) !== Str::lower($this->leafName())) {
                $fail("Type {$this->leafName()} exactly to confirm.");
            }
        };
    }
}
