<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Backup\DisableBackups;
use App\Actions\Backup\EnableBackups;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Turns a PC's backups on, generating its repository password, and off.
 * The password is never shown.
 */
final class BackupCredentials extends Component
{
    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    public function enable(EnableBackups $enableBackups): void
    {
        $this->authorize('manageBackups', $this->device);

        $enableBackups($this->device);
        $this->dispatch('backup-credentials-saved');

        Flux::toast(text: 'Back up now to create its repository on the backup server.', heading: "Backups enabled for {$this->device->hostname}", variant: 'success');
    }

    public function disable(DisableBackups $disableBackups): void
    {
        $this->authorize('manageBackups', $this->device);

        $disableBackups($this->device);
        $this->dispatch('backup-credentials-saved');

        Flux::toast(text: 'Its snapshots on scarif stay, and enabling it again carries on in the same repository.', heading: "Backups disabled for {$this->device->hostname}", variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.devices.backup-credentials');
    }
}
