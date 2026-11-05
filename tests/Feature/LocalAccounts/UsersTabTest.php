<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\DeviceTab;
use App\Exceptions\LocalAccountCannotBeChanged;
use App\Livewire\Devices\Users;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLocalAccount;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->device = Device::factory()->windows()->active()->create(['agent_version' => '0.11.0', 'hostname' => 'OFFICE-PC1']);
});

function reportedAccount(Device $device, array $attributes = []): DeviceLocalAccount
{
    return DeviceLocalAccount::factory()->for($device)->create($attributes);
}

function queuedLocalAccountCommand(string $slug): ?DeviceCommand
{
    return DeviceCommand::query()->where('script_id', Script::findSystem($slug)->id)->first();
}

it('is a routed device tab', function (): void {
    $this->actingAs($this->user)
        ->get(route(DeviceTab::Users->routeName(), $this->device))
        ->assertSuccessful()
        ->assertSeeLivewire(Users::class)
        ->assertSee(route('devices.users', $this->device));
});

it('lists each account with its badges and last logon', function (): void {
    StaffAccount::factory()->create(['username' => 'sophie'])->devices()->attach($this->device);
    reportedAccount($this->device, ['username' => 'Sophie', 'sid' => 'S-1-5-21-1-2-3-1001', 'is_signed_in' => true, 'is_admin' => false, 'last_logon_at' => '2026-11-04 08:15:00']);
    reportedAccount($this->device, ['username' => 'Administrator', 'sid' => 'S-1-5-21-1-2-3-500', 'is_enabled' => false, 'is_admin' => true, 'is_signed_in' => false, 'last_logon_at' => null]);
    reportedAccount($this->device, ['username' => 'olduser', 'sid' => 'S-1-5-21-1-2-3-1002', 'is_signed_in' => false]);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->assertSeeHtml('data-local-account="Sophie"')
        ->assertSeeHtml('data-badge="managed"')
        ->assertSeeHtml('data-badge="signed-in"')
        ->assertSeeHtml('data-badge="built-in"')
        ->assertSeeHtml('data-badge="admin"')
        ->assertSeeHtml('data-badge="disabled"')
        ->assertSeeHtml('data-badge="unmanaged"')
        ->assertSee('Never');
});

it('says when the agent is too old to report accounts', function (): void {
    $this->device->update(['agent_version' => '0.10.0']);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->assertSeeHtml('data-local-accounts-unsupported')
        ->assertSee(config('local_accounts.min_agent_version'));
});

it('says the accounts are Windows only on a Linux server', function (): void {
    $server = Device::factory()->active()->monitorOnly()->create(['agent_version' => '0.11.0']);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $server])
        ->assertSeeHtml('data-local-accounts-windows-only');
});

it('offers actions only on unmanaged accounts and never deletes built-ins or the signed-in user', function (): void {
    StaffAccount::factory()->create(['username' => 'sophie'])->devices()->attach($this->device);
    $managed = reportedAccount($this->device, ['username' => 'sophie']);
    $builtIn = reportedAccount($this->device, ['username' => 'Administrator', 'sid' => 'S-1-5-21-1-2-3-500']);
    $signedIn = reportedAccount($this->device, ['username' => 'ben', 'is_signed_in' => true]);
    $loose = reportedAccount($this->device, ['username' => 'temp', 'is_signed_in' => false]);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->assertDontSeeHtml("data-adopt=\"{$managed->id}\"")
        ->assertDontSeeHtml("data-delete=\"{$builtIn->id}\"")
        ->assertDontSeeHtml("data-delete=\"{$signedIn->id}\"")
        ->assertSeeHtml("data-adopt=\"{$loose->id}\"")
        ->assertSeeHtml("data-disable=\"{$loose->id}\"")
        ->assertSeeHtml("data-delete=\"{$loose->id}\"");
});

it('adopts an unmanaged account as a staff account targeting this PC, leaving its password alone', function (): void {
    $account = reportedAccount($this->device, ['username' => 'temp', 'full_name' => 'Temp Worker', 'is_admin' => true, 'is_enabled' => true]);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->call('startAdopt', $account->id)
        ->assertSet('resetPassword', false)
        ->call('adopt')
        ->assertHasNoErrors();

    $staff = StaffAccount::query()->sole();
    $sync = queuedLocalAccountCommand('sync-local-user');

    expect($staff->username)->toBe('temp')
        ->and($staff->full_name)->toBe('Temp Worker')
        ->and($staff->is_admin)->toBeTrue()
        ->and($staff->devices()->pluck('devices.id')->all())->toBe([$this->device->id])
        ->and($sync?->device_id)->toBe($this->device->id)
        ->and($sync?->parameters['ResetPassword'])->toBe('false');
});

it('resets the password to the temp password on adopt only when ticked', function (): void {
    $account = reportedAccount($this->device, ['username' => 'temp']);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->call('startAdopt', $account->id)
        ->set('resetPassword', true)
        ->call('adopt')
        ->assertHasNoErrors();

    expect(queuedLocalAccountCommand('sync-local-user')?->parameters['ResetPassword'])->toBe('true');
});

it('will not adopt a name another staff account already has', function (): void {
    StaffAccount::factory()->create(['username' => 'temp']);
    $account = reportedAccount($this->device, ['username' => 'temp']);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->call('startAdopt', $account->id)
        ->call('adopt')
        ->assertHasErrors('username');

    expect(StaffAccount::query()->count())->toBe(1);
});

it('queues disable-local-user for an unmanaged account', function (): void {
    $account = reportedAccount($this->device, ['username' => 'temp']);

    Livewire::actingAs($this->user)->test(Users::class, ['device' => $this->device])->call('disable', $account->id);

    expect(queuedLocalAccountCommand('disable-local-user')?->parameters)->toMatchArray(['Username' => 'temp']);
});

it('queues remove-local-user for an unmanaged account', function (): void {
    $account = reportedAccount($this->device, ['username' => 'temp', 'is_signed_in' => false]);

    Livewire::actingAs($this->user)->test(Users::class, ['device' => $this->device])->call('delete', $account->id);

    expect(queuedLocalAccountCommand('remove-local-user')?->parameters)->toMatchArray(['Username' => 'temp']);
});

it('refuses to change built-in, signed-in, unknown-session or managed accounts', function (array $attributes, string $method, bool $isManaged): void {
    $account = reportedAccount($this->device, ['username' => 'temp', 'sid' => 'S-1-5-21-1-2-3-1001', 'is_signed_in' => false, ...$attributes]);

    if ($isManaged) {
        StaffAccount::factory()->create(['username' => 'temp'])->devices()->attach($this->device);
    }

    $page = Livewire::actingAs($this->user)->test(Users::class, ['device' => $this->device]);

    expect(fn () => $page->call($method, $account->id))->toThrow(LocalAccountCannotBeChanged::class);

    expect(DeviceCommand::query()->exists())->toBeFalse();
})->with([
    'delete a built-in' => [['sid' => 'S-1-5-21-1-2-3-500'], 'delete', false],
    'delete the signed-in user' => [['is_signed_in' => true], 'delete', false],
    'delete with sessions unknown' => [['is_signed_in' => null], 'delete', false],
    'disable a built-in' => [['sid' => 'S-1-5-21-1-2-3-501'], 'disable', false],
    'delete a managed account' => [[], 'delete', true],
    'disable a managed account' => [[], 'disable', true],
    'adopt a managed account' => [[], 'startAdopt', true],
    'adopt a built-in' => [['sid' => 'S-1-5-21-1-2-3-500'], 'startAdopt', false],
]);

it('only acts on accounts reported by this PC', function (): void {
    $other = reportedAccount(Device::factory()->windows()->active()->create(), ['username' => 'temp']);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->call('delete', $other->id)
        ->assertNotFound();
});

it('authorises every mutating action', function (string $method, string $ability): void {
    $account = reportedAccount($this->device, ['username' => 'temp', 'is_signed_in' => false]);
    Gate::before(fn (User $user, string $checked): ?bool => $checked === $ability ? false : null);

    Livewire::actingAs($this->user)
        ->test(Users::class, ['device' => $this->device])
        ->call($method, $account->id)
        ->assertForbidden();
})->with([
    'adopt needs commands' => ['startAdopt', 'runCommands'],
    'adopt needs staff create' => ['startAdopt', 'create'],
    'disable' => ['disable', 'runCommands'],
    'delete' => ['delete', 'runCommands'],
]);
