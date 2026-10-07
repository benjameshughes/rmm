<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\Backup\SetBackupCredentials;
use App\Models\Device;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Sets or replaces a PC's backup credentials. Passwords are write-only: the
 * form starts blank, says only whether each is set, and clears after saving.
 */
final class BackupCredentials extends Component
{
    public Device $device;

    public string $restUsername = '';

    public string $restPassword = '';

    public string $repositoryPassword = '';

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
        $this->restUsername = $device->backup_rest_username ?? strtolower($device->hostname);
    }

    public function save(SetBackupCredentials $setCredentials): void
    {
        $this->authorize('manageBackups', $this->device);

        $passwordRule = $this->device->hasBackupCredentials ? 'nullable' : 'required';

        $this->validate([
            'restUsername' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,63}$/'],
            'restPassword' => [$passwordRule, 'string', 'min:8', 'max:200', 'not_regex:/[\x00-\x1f]/'],
            'repositoryPassword' => [$passwordRule, 'string', 'min:8', 'max:200', 'not_regex:/[\x00-\x1f]/'],
        ], [
            'restUsername.required' => 'Enter the rest-server username, normally the lowercase hostname.',
            'restUsername.regex' => 'Use lowercase letters, digits, dots, dashes and underscores only, as on scarif.',
            'restPassword.required' => 'Enter the rest-server password set up for this PC on scarif.',
            'repositoryPassword.required' => 'Enter the password this PC\'s restic repository was created with.',
            '*.min' => 'Passwords must be at least :min characters.',
            '*.max' => 'Passwords may not be longer than :max characters.',
            '*.not_regex' => 'Passwords cannot contain line breaks or control characters.',
        ]);

        $setCredentials($this->device, $this->restUsername, $this->restPassword, $this->repositoryPassword);

        $this->reset('restPassword', 'repositoryPassword');
        $this->dispatch('backup-credentials-saved');

        Flux::toast(text: 'Stored encrypted. Back up now to check they work.', heading: "Backup credentials saved for {$this->device->hostname}", variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.devices.backup-credentials', [
            'hasRestPassword' => $this->device->getRawOriginal('backup_rest_password') !== null,
            'hasRepositoryPassword' => $this->device->getRawOriginal('backup_repository_password') !== null,
        ]);
    }
}
