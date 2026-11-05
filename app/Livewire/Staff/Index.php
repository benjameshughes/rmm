<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Staff\CreateStaffAccount;
use App\Actions\Staff\DeleteStaffAccount;
use App\Actions\Staff\DisableStaffAccount;
use App\Actions\Staff\LockStaffScreens;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SyncStaffAccount;
use App\Actions\Staff\UpdateStaffAccount;
use App\DTOs\Staff\StaffAccountDetails;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\DeviceLocalAccount;
use App\Models\StaffAccount;
use App\Models\Tag;
use App\Queries\DeviceLocalAccountQueries;
use App\Queries\StaffAccountQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('components.layouts.app')]
final class Index extends Component
{
    public string $username = '';

    public string $fullName = '';

    public bool $isAdmin = false;

    public bool $isEnabled = true;

    /** @var array<int, string> */
    public array $deviceIds = [];

    /** @var array<int, string> */
    public array $deviceGroupIds = [];

    /** @var array<int, string> */
    public array $tagIds = [];

    public bool $showModal = false;

    #[Locked]
    public ?int $editingId = null;

    private StaffAccountQueries $staffAccounts;

    private DeviceLocalAccountQueries $localAccounts;

    public function boot(StaffAccountQueries $staffAccounts, DeviceLocalAccountQueries $localAccounts): void
    {
        $this->staffAccounts = $staffAccounts;
        $this->localAccounts = $localAccounts;
    }

    public function mount(): void
    {
        $this->authorize('viewAny', StaffAccount::class);
    }

    #[On('echo-private:devices,CommandUpdated')]
    public function refreshResults(): void
    {
        unset($this->latestSyncs);
    }

    public function create(): void
    {
        $this->authorize('create', StaffAccount::class);
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(StaffAccount $account): void
    {
        $this->authorize('update', $account);
        $this->resetForm();

        $this->editingId = $account->id;
        $this->username = $account->username;
        $this->fullName = $account->full_name;
        $this->isAdmin = $account->is_admin;
        $this->isEnabled = $account->is_enabled;
        $this->deviceIds = $account->devices()->pluck('devices.id')->map(fn (int $id): string => (string) $id)->all();
        $this->deviceGroupIds = $account->deviceGroups()->pluck('device_groups.id')->map(fn (int $id): string => (string) $id)->all();
        $this->tagIds = $account->tags()->pluck('tags.id')->map(fn (int $id): string => (string) $id)->all();
        $this->showModal = true;
    }

    public function save(CreateStaffAccount $createAccount, UpdateStaffAccount $updateAccount): void
    {
        $account = $this->editingId === null ? null : StaffAccount::findOrFail($this->editingId);
        $account === null ? $this->authorize('create', StaffAccount::class) : $this->authorize('update', $account);

        $this->validate($this->rules($account), $this->messages());

        $details = new StaffAccountDetails(
            username: $this->username,
            fullName: $this->fullName,
            isAdmin: $this->isAdmin,
            isEnabled: $this->isEnabled,
            deviceIds: array_map(intval(...), $this->deviceIds),
            deviceGroupIds: array_map(intval(...), $this->deviceGroupIds),
            tagIds: array_map(intval(...), $this->tagIds),
        );

        $account === null
            ? $createAccount($details, auth()->user())
            : $updateAccount($account, $details, auth()->user());

        $this->resetForm();
        Flux::toast(text: "{$details->username} is queued on its PCs.", heading: 'Staff account saved', variant: 'success');
    }

    public function sync(StaffAccount $account, SyncStaffAccount $syncAccount): void
    {
        $this->authorize('update', $account);

        $queued = $syncAccount($account, auth()->user());

        Flux::toast(text: "{$account->username} on {$queued} ".Str::plural('PC', $queued).'.', heading: 'Sync queued', variant: 'success');
    }

    public function resetAccountPassword(StaffAccount $account, ResetStaffPassword $resetPassword): void
    {
        $this->authorize('update', $account);

        $queued = $resetPassword($account, auth()->user());

        Flux::toast(text: "{$account->username} must change it at next logon. Queued on {$queued} ".Str::plural('PC', $queued).'.', heading: 'Password reset to the temp password', variant: 'success');
    }

    public function disable(StaffAccount $account, DisableStaffAccount $disableAccount): void
    {
        $this->authorize('update', $account);

        $queued = $disableAccount($account, auth()->user());

        Flux::toast(text: "{$account->username} on {$queued} ".Str::plural('PC', $queued).'.', heading: 'Disable queued', variant: 'success');
    }

    public function delete(StaffAccount $account, DeleteStaffAccount $deleteAccount): void
    {
        $this->authorize('delete', $account);

        $queued = $deleteAccount($account, auth()->user());

        Flux::toast(text: "{$account->username} is being removed from {$queued} ".Str::plural('PC', $queued).'.', heading: 'Staff account deleted', variant: 'success');
    }

    public function lockScreens(LockStaffScreens $lockScreens): void
    {
        $this->authorize('lock-staff-screens');

        $queued = $lockScreens(auth()->user());
        $minutes = config('staff.screen_lock_idle_minutes');

        Flux::toast(text: "{$minutes} idle minutes on {$queued} ".Str::plural('PC', $queued).'.', heading: 'Screen lock queued', variant: 'success');
    }

    public function resetForm(): void
    {
        $this->reset('username', 'fullName', 'isAdmin', 'isEnabled', 'deviceIds', 'deviceGroupIds', 'tagIds', 'editingId', 'showModal');
        $this->resetErrorBag();
    }

    /** @return EloquentCollection<int, StaffAccount> */
    #[Computed]
    public function accounts(): EloquentCollection
    {
        return StaffAccount::query()
            ->with(['devices:id,hostname', 'deviceGroups:id,name', 'tags:id,name,color'])
            ->orderBy('username')
            ->get();
    }

    /**
     * Each account's latest logon on any PC, as the agents last reported it.
     *
     * @return Collection<string, DeviceLocalAccount> Keyed by lower-cased username
     */
    #[Computed]
    public function lastLogons(): Collection
    {
        return $this->localAccounts->latestLogons($this->accounts->pluck('username'));
    }

    /** @return Collection<int, Collection<int, DeviceCommand>> */
    #[Computed]
    public function latestSyncs(): Collection
    {
        return $this->staffAccounts->latestSyncs(collect($this->accounts->modelKeys()));
    }

    public function render(): View
    {
        return view('livewire.staff.index', [
            'devices' => Device::query()->acceptsCommands()->runsWindows()->orderBy('hostname')->get(['id', 'hostname']),
            'groups' => DeviceGroup::query()->orderBy('name')->get(['id', 'name']),
            'tags' => Tag::query()->orderBy('name')->get(['id', 'name']),
            'screenLockMinutes' => config('staff.screen_lock_idle_minutes'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?StaffAccount $account): array
    {
        return [
            'username' => $account === null
                ? ['required', 'string', 'regex:'.config('staff.username_pattern'), Rule::unique('staff_accounts', 'username')]
                : ['string'],
            'fullName' => ['required', 'string', 'max:'.config('staff.full_name_max_length')],
            'isAdmin' => ['boolean'],
            'isEnabled' => ['boolean'],
            'deviceIds' => ['array'],
            'deviceIds.*' => ['integer', 'exists:devices,id'],
            'deviceGroupIds' => ['array'],
            'deviceGroupIds.*' => ['integer', 'exists:device_groups,id'],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', 'exists:tags,id'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'username.regex' => 'Use up to 20 letters, digits, dots, dashes or underscores, starting with a letter or digit.',
            'username.unique' => 'There is already a staff account with that username.',
            'fullName.required' => 'Enter the person\'s full name.',
        ];
    }
}
