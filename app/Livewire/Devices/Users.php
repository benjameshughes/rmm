<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\LocalAccount\AdoptLocalAccount;
use App\Actions\LocalAccount\DisableLocalAccount;
use App\Actions\LocalAccount\RemoveLocalAccount;
use App\DTOs\LocalAccount\LocalAccountRow;
use App\Enums\DeviceTab;
use App\Enums\ScriptPlatform;
use App\Exceptions\LocalAccountCannotBeChanged;
use App\Models\Device;
use App\Models\DeviceLocalAccount;
use App\Models\StaffAccount;
use App\Queries\DeviceLocalAccountQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A PC's local Windows accounts as its agent last reported them, marked
 * managed when a staff account targeting the PC has the same name. An
 * unmanaged one can be adopted as a staff account, disabled or deleted.
 */
#[Layout('components.layouts.app')]
final class Users extends Component
{
    public Device $device;

    #[Locked]
    public ?int $adoptingId = null;

    public string $password = '';

    public bool $showAdopt = false;

    private DeviceLocalAccountQueries $localAccounts;

    public function boot(DeviceLocalAccountQueries $localAccounts): void
    {
        $this->localAccounts = $localAccounts;
    }

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    #[On('echo-private:devices.{device.id},DeviceUpdated')]
    public function refreshDevice(): void
    {
        $this->device->refresh();
    }

    public function startAdopt(int $accountId): void
    {
        $account = $this->account($accountId);
        $this->authorize('runCommands', $this->device);
        $this->authorize('create', StaffAccount::class);

        throw_if($account->isBuiltIn, LocalAccountCannotBeChanged::builtIn($account));
        throw_if($this->localAccounts->isManaged($account), LocalAccountCannotBeChanged::managed($account));

        $this->resetErrorBag();
        $this->adoptingId = $account->id;
        $this->password = Str::password(config('staff.generated_password_length'));
        $this->showAdopt = true;
    }

    public function generatePassword(): void
    {
        $this->password = Str::password(config('staff.generated_password_length'));
    }

    public function adopt(AdoptLocalAccount $adoptAccount): void
    {
        $account = $this->account((int) $this->adoptingId);
        $this->authorize('runCommands', $this->device);
        $this->authorize('create', StaffAccount::class);

        $this->validate(
            ['password' => ['required', 'string', 'min:'.config('staff.password_min_length'), 'max:'.config('staff.password_max_length')]],
            ['password.required' => 'Enter or generate a password. The PC\'s current one cannot be read.', 'password.min' => 'The password must be at least :min characters.'],
        );
        validator(
            ['username' => $account->username],
            ['username' => [Rule::unique('staff_accounts', 'username')]],
            ['username.unique' => "There is already a staff account named {$account->username}. Add this PC to its targets on the Staff page."],
        )->validate();

        $adoptAccount($account, $this->password, auth()->user());

        $this->cancelAdopt();
        Flux::toast(text: "{$account->username} is now a staff account. Its new password is queued on {$this->device->hostname}.", heading: 'Account adopted', variant: 'success');
    }

    public function cancelAdopt(): void
    {
        $this->reset('adoptingId', 'password', 'showAdopt');
        $this->resetErrorBag();
    }

    public function disable(int $accountId, DisableLocalAccount $disableAccount): void
    {
        $account = $this->account($accountId);
        $this->authorize('runCommands', $this->device);

        $disableAccount($account, auth()->user());

        Flux::toast(text: "{$account->username} on {$this->device->hostname}.", heading: 'Disable queued', variant: 'success');
    }

    public function delete(int $accountId, RemoveLocalAccount $removeAccount): void
    {
        $account = $this->account($accountId);
        $this->authorize('runCommands', $this->device);

        $removeAccount($account, auth()->user());

        Flux::toast(text: "{$account->username} is being removed from {$this->device->hostname}.", heading: 'Delete queued', variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.devices.users', [
            'isWindows' => $this->device->platform() === ScriptPlatform::Windows,
            'isSupported' => $this->device->reportsLocalAccounts(),
            'rows' => $this->rows(),
            'adopting' => $this->adoptingId === null ? null : $this->device->localAccounts()->find($this->adoptingId),
            'minAgentVersion' => config('local_accounts.min_agent_version'),
        ])->title(DeviceTab::Users->pageTitle($this->device));
    }

    /**
     * @return Collection<int, LocalAccountRow>
     */
    private function rows(): Collection
    {
        $managed = $this->localAccounts->managedUsernames($this->device);

        return $this->device->localAccounts()
            ->orderBy('username')
            ->get()
            ->map(fn (DeviceLocalAccount $account): LocalAccountRow => new LocalAccountRow(
                account: $account,
                isManaged: $managed->contains(Str::lower($account->username)),
            ));
    }

    private function account(int $accountId): DeviceLocalAccount
    {
        return $this->device->localAccounts()->findOrFail($accountId);
    }
}
