<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
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
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->group = DeviceGroup::factory()->create();
    $this->first = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1', 'device_group_id' => $this->group->id]);
    $this->second = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1']);
});

function staffDetails(array $overrides = []): StaffAccountDetails
{
    return new StaffAccountDetails(...[
        'username' => 'sophie',
        'fullName' => 'Sophie Smith',
        'isAdmin' => false,
        'isEnabled' => true,
        'deviceIds' => [],
        'deviceGroupIds' => [],
        'tagIds' => [],
        ...$overrides,
    ]);
}

/** @return array<int, string> Keyed by device id */
function queuedOf(string $slug): array
{
    return DeviceCommand::query()
        ->where('script_id', Script::findSystem($slug)->id)
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (DeviceCommand $command): array => [$command->device_id => $command->parameters])
        ->all();
}

it('creates an account with its targets and queues sync-local-user to each resolved PC', function (): void {
    $account = app(CreateStaffAccount::class)(
        staffDetails(['isAdmin' => true, 'deviceIds' => [$this->second->id], 'deviceGroupIds' => [$this->group->id]]),
        'correct-horse-battery',
        $this->user,
    );

    $expected = ['StaffAccountId' => (string) $account->id, 'Username' => 'sophie', 'FullName' => 'Sophie Smith', 'IsAdmin' => 'true', 'IsEnabled' => 'true'];

    expect($account->password)->toBe('correct-horse-battery')
        ->and(queuedOf('sync-local-user'))->toEqual([$this->first->id => $expected, $this->second->id => $expected]);
});

it('updates an account, removes it from PCs it no longer targets and keeps the username', function (): void {
    $account = StaffAccount::factory()->create(['username' => 'sophie', 'password' => 'old-password-123']);
    $account->devices()->attach([$this->first->id, $this->second->id]);

    app(UpdateStaffAccount::class)($account, staffDetails(['username' => 'renamed', 'fullName' => 'Sophie Jones', 'deviceIds' => [$this->first->id]]), $this->user);

    expect($account->fresh()->username)->toBe('sophie')
        ->and($account->fresh()->full_name)->toBe('Sophie Jones')
        ->and($account->fresh()->password)->toBe('old-password-123')
        ->and(queuedOf('remove-local-user'))->toBe([$this->second->id => ['Username' => 'sophie']])
        ->and(array_keys(queuedOf('sync-local-user')))->toBe([$this->first->id]);
});

it('generates a new password on reset, or takes the one given, and syncs it', function (): void {
    $account = StaffAccount::factory()->create();
    $account->devices()->attach($this->first);

    $generated = app(ResetStaffPassword::class)($account, $this->user);
    $given = app(ResetStaffPassword::class)($account, $this->user, 'chosen-password-1');

    expect(strlen($generated))->toBe(config('staff.generated_password_length'))
        ->and($given)->toBe('chosen-password-1')
        ->and($account->fresh()->password)->toBe('chosen-password-1')
        ->and(DeviceCommand::query()->count())->toBe(2);
});

it('disables an account and syncs it as disabled', function (): void {
    $account = StaffAccount::factory()->create();
    $account->devices()->attach($this->first);

    expect(app(DisableStaffAccount::class)($account, $this->user))->toBe(1)
        ->and($account->fresh()->is_enabled)->toBeFalse()
        ->and(queuedOf('sync-local-user')[$this->first->id]['IsEnabled'])->toBe('false');
});

it('queues removal on every targeted PC, then deletes the account', function (): void {
    $account = StaffAccount::factory()->create(['username' => 'leaver']);
    $account->deviceGroups()->attach($this->group);
    $account->devices()->attach($this->second);

    expect(app(DeleteStaffAccount::class)($account, $this->user))->toBe(2)
        ->and(StaffAccount::query()->exists())->toBeFalse()
        ->and(queuedOf('remove-local-user'))->toEqual([$this->first->id => ['Username' => 'leaver'], $this->second->id => ['Username' => 'leaver']]);
});

it('skips monitor-only and outdated PCs when syncing', function (): void {
    $outdated = Device::factory()->windows()->active()->create(['agent_version' => '0.5.0']);
    $account = StaffAccount::factory()->create();
    $account->devices()->attach([$this->first->id, $outdated->id]);

    expect(app(SyncStaffAccount::class)($account, $this->user))->toBe(1);
});

it('queues the screen lock once per PC any staff account targets, with the configured minutes', function (): void {
    config(['staff.screen_lock_idle_minutes' => 7]);
    StaffAccount::factory()->create()->devices()->attach([$this->first->id, $this->second->id]);
    StaffAccount::factory()->create()->deviceGroups()->attach($this->group);

    expect(app(LockStaffScreens::class)($this->user))->toBe(2)
        ->and(queuedOf('set-screen-lock'))->toEqual([$this->first->id => ['IdleMinutes' => '7'], $this->second->id => ['IdleMinutes' => '7']]);
});
