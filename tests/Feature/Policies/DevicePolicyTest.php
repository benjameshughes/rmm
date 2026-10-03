<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\AlertStatus;
use App\Enums\DeviceStatus;
use App\Livewire\AlertBell;
use App\Livewire\Alerts\Index as AlertsIndex;
use App\Livewire\Commands\Detail;
use App\Livewire\Devices\Apps;
use App\Livewire\Devices\Commands;
use App\Livewire\Devices\Details;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Livewire\Devices\Metrics;
use App\Livewire\Devices\Overview;
use App\Livewire\Devices\Pending;
use App\Livewire\Scripts\Show as ScriptsShow;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
});

function denyAbility(string $ability): void
{
    Gate::before(fn (User $user, string $checked): ?bool => $checked === $ability ? false : null);
}

it('lets an authenticated user do everything in this single-admin tool', function (): void {
    $device = Device::factory()->active()->create();
    $pending = Device::factory()->create(['status' => DeviceStatus::Pending]);
    $abilities = ['view', 'approve', 'reject', 'runCommands', 'runAdHocCommand', 'wake', 'resetEnrolment', 'manageGroupsAndTags', 'delete'];

    collect($abilities)->each(fn (string $ability) => expect($this->user->can($ability, $device))->toBeTrue());
    expect($this->user->can('viewAny', Device::class))->toBeTrue()
        ->and($this->user->can('approve', $pending))->toBeTrue()
        ->and($this->user->can('viewAny', Alert::class))->toBeTrue()
        ->and($this->user->can('update', Alert::factory()->create()))->toBeTrue();
});

it('gates viewing the device pages through the policy', function (string $component): void {
    denyAbility('viewAny');

    Livewire::actingAs($this->user)->test($component)->assertForbidden();
})->with([
    'device list' => [Index::class],
    'pending list' => [Pending::class],
]);

it('gates viewing a device through the policy', function (string $component): void {
    denyAbility('view');

    Livewire::actingAs($this->user)
        ->test($component, ['device' => Device::factory()->active()->create()])
        ->assertForbidden();
})->with([
    'header' => [Header::class],
    'overview' => [Overview::class],
    'metrics' => [Metrics::class],
    'commands' => [Commands::class],
    'apps' => [Apps::class],
    'details' => [Details::class],
]);

it('gates running commands from the device header through the policy', function (string $method): void {
    $device = Device::factory()->active()->create();
    $component = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device]);
    denyAbility('runCommands');

    $component->call($method)->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
})->with(['powerOff', 'restart', 'logOff', 'checkForUpdates', 'updateAgent']);

it('gates running commands from the device list through the policy', function (): void {
    $device = Device::factory()->active()->create();
    $single = Livewire::actingAs($this->user)->test(Index::class);
    $bulk = Livewire::actingAs($this->user)->test(Index::class)->set('selectedDevices', [(string) $device->id]);
    denyAbility('runCommands');

    $single->call('restart', $device->id)->assertForbidden();
    $bulk->call('bulkRestart')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('gates running a script from the script page through the policy', function (): void {
    $device = Device::factory()->active()->create();
    denyAbility('runCommands');

    Livewire::actingAs($this->user)
        ->test(ScriptsShow::class, ['script' => Script::factory()->create()])
        ->set('selectedDeviceId', $device->id)
        ->call('executeOnDevice')
        ->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('gates approving and rejecting through the policy', function (string $ability): void {
    $device = Device::factory()->create(['status' => DeviceStatus::Pending]);
    $component = Livewire::actingAs($this->user)->test(Pending::class);
    denyAbility($ability);

    $component->call($ability, $device->id)->assertForbidden();

    expect($device->fresh()->status)->toBe(DeviceStatus::Pending);
})->with(['approve', 'reject']);

it('gates resetting enrolment through the policy', function (): void {
    $device = Device::factory()->withApiKey('KEY-KEEP')->create();
    $component = Livewire::actingAs($this->user)->test(Details::class, ['device' => $device]);
    denyAbility('resetEnrolment');

    $component->call('resetEnrolment')->assertForbidden();

    expect($device->fresh()->status)->toBe(DeviceStatus::Active);
});

it('gates group and tag changes through the policy', function (): void {
    $device = Device::factory()->active()->create();
    $group = DeviceGroup::factory()->create();
    $component = Livewire::actingAs($this->user)->test(Details::class, ['device' => $device]);
    denyAbility('manageGroupsAndTags');

    $component->set('selectedGroupId', (string) $group->id)->assertForbidden();

    expect($device->fresh()->device_group_id)->toBeNull();
});

it('gates opening command output through the policy', function (): void {
    $command = DeviceCommand::factory()->create();
    denyAbility('viewAny');

    Livewire::actingAs($this->user)
        ->test(Detail::class)
        ->dispatch('show-command', commandId: $command->id)
        ->assertForbidden();
});

it('gates the alert pages and actions through the alert policy', function (): void {
    $alert = Alert::factory()->triggered()->create();
    $acknowledging = Livewire::actingAs($this->user)->test(AlertsIndex::class);
    $resolving = Livewire::actingAs($this->user)->test(AlertsIndex::class);
    denyAbility('update');

    $acknowledging->call('acknowledge', $alert->id)->assertForbidden();
    $resolving->call('resolve', $alert->id)->assertForbidden();

    denyAbility('viewAny');

    Livewire::actingAs($this->user)->test(AlertsIndex::class)->assertForbidden();
    Livewire::withoutLazyLoading()->actingAs($this->user)->test(AlertBell::class)->assertForbidden();
    expect($alert->fresh()->status)->toBe(AlertStatus::Triggered);
});
