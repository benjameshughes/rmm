<?php

declare(strict_types=1);

namespace App\Livewire\Software;

use App\Actions\Software\QueuePackageCommands;
use App\DTOs\Software\PackageCommandPlan;
use App\Enums\InstallTarget;
use App\Enums\PackageAction;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Tag;
use App\Queries\InstallTargetQueries;
use App\Queries\SoftwareQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Installs one winget package on many devices: picked from the IDs the fleet
 * already has or typed in, sent to the ticked devices, a group, a tag or every
 * Windows device, skipping devices whose inventory already lists it. Opened
 * from anywhere with the `open-install-software` event.
 */
final class InstallSoftware extends Component
{
    public bool $showModal = false;

    public string $packageId = '';

    #[Locked]
    public bool $isPackageFixed = false;

    /** @var array<int, int> */
    #[Locked]
    public array $deviceIds = [];

    public string $target = InstallTarget::All->value;

    public string $targetGroupId = '';

    public string $targetTagId = '';

    private SoftwareQueries $software;

    private InstallTargetQueries $targets;

    public function boot(SoftwareQueries $software, InstallTargetQueries $targets): void
    {
        $this->software = $software;
        $this->targets = $targets;
    }

    /**
     * @param  array<int, int|string>  $deviceIds  The devices ticked on the devices table, if any
     * @param  string|null  $packageId  Fixed when opened from a package's page
     */
    #[On('open-install-software')]
    public function open(array $deviceIds = [], ?string $packageId = null): void
    {
        $this->authorize('viewAny', Device::class);

        $this->resetValidation();
        $this->reset('targetGroupId', 'targetTagId');
        $this->deviceIds = collect($deviceIds)->map(fn (int|string $id): int => (int) $id)->filter()->unique()->values()->all();
        $this->packageId = $packageId ?? '';
        $this->isPackageFixed = $packageId !== null;
        $this->target = ($this->deviceIds === [] ? InstallTarget::All : InstallTarget::Selected)->value;
        $this->showModal = true;
    }

    /** @return BaseCollection<int, string> */
    #[Computed]
    public function suggestions(): BaseCollection
    {
        return $this->isPackageFixed || ! $this->showModal ? collect() : $this->software->knownWingetPackageIds(trim($this->packageId));
    }

    /**
     * Who gets it, and how many already have it. Empty until a target is fully chosen.
     */
    #[Computed]
    public function plan(): PackageCommandPlan
    {
        $packageId = trim($this->packageId);
        $target = InstallTarget::tryFrom($this->target);
        $devices = $packageId === '' || $target === null
            ? new Collection
            : $this->targets->devices(
                target: $target,
                packageId: $packageId,
                deviceIds: $this->deviceIds,
                groupId: $this->numericOrNull($this->targetGroupId),
                tagId: $this->numericOrNull($this->targetTagId),
            )->get();

        [$alreadyHave, $missing] = $devices->partition(fn (Device $device): bool => $device->software->isNotEmpty());

        return new PackageCommandPlan(
            action: PackageAction::Install,
            devicesByPackage: $missing->isEmpty() ? collect() : collect([$packageId => $missing->values()]),
            skippedCount: $alreadyHave->count(),
        );
    }

    public function install(QueuePackageCommands $queue): void
    {
        $this->packageId = trim($this->packageId);
        $this->validate();

        $plan = $this->plan;

        if ($plan->isEmpty()) {
            $this->addError('target', 'No devices to install on: every device in this target already has it, or there are none.');

            return;
        }

        $plan->devices()->each(fn (Device $device) => $this->authorize('runCommands', $device));
        $queued = $queue($plan, auth()->user());

        $this->showModal = false;
        $this->dispatch('command-queued');

        Flux::toast(
            text: $queued < $plan->commandCount() ? 'Devices whose agent is older than '.config('agent.parameters_min_version').' were skipped. Update their agent first.' : 'Offline devices install it when they next check in.',
            heading: "Install of {$this->packageId} queued on {$queued} of {$plan->commandCount()} ".Str::plural('device', $plan->commandCount()),
            variant: $queued < $plan->commandCount() ? 'warning' : 'success',
        );
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'packageId' => ['required', 'string', 'max:'.config('software.package_id_max_length'), 'regex:'.config('software.package_id_pattern')],
            'target' => ['required', Rule::enum(InstallTarget::class)],
            'targetGroupId' => [Rule::requiredIf($this->target === InstallTarget::Group->value), 'nullable', Rule::exists('device_groups', 'id')],
            'targetTagId' => [Rule::requiredIf($this->target === InstallTarget::Tag->value), 'nullable', Rule::exists('tags', 'id')],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'packageId.required' => 'Choose a package or type its winget ID, for example Mozilla.Firefox.',
            'packageId.max' => 'A winget package ID is at most :max characters.',
            'packageId.regex' => 'That is not a winget package ID. Use letters, digits and . _ + - only, for example Mozilla.Firefox.',
            'target.required' => 'Choose which devices to install it on.',
            'target.enum' => 'Choose which devices to install it on.',
            'targetGroupId.required' => 'Choose a group.',
            'targetGroupId.exists' => 'That group no longer exists.',
            'targetTagId.required' => 'Choose a tag.',
            'targetTagId.exists' => 'That tag no longer exists.',
        ];
    }

    private function numericOrNull(string $value): ?int
    {
        return ctype_digit($value) ? (int) $value : null;
    }

    public function render(): View
    {
        return view('livewire.software.install-software', [
            'targets' => collect(InstallTarget::cases())->reject(fn (InstallTarget $target): bool => $target === InstallTarget::Selected && $this->deviceIds === []),
            'groups' => $this->showModal ? DeviceGroup::query()->orderBy('name')->get() : collect(),
            'tags' => $this->showModal ? Tag::query()->orderBy('name')->get() : collect(),
        ]);
    }
}
