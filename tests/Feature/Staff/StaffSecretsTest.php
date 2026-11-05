<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Actions\Staff\CreateStaffAccount;
use App\Actions\Staff\SyncStaffAccount;
use App\DTOs\Staff\StaffAccountDetails;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->device = Device::factory()->windows()->active()->withApiKey('STAFF-PC-KEY')->create(['agent_version' => '0.7.1']);
});

function pullStaffCommand(string $apiKey = 'STAFF-PC-KEY'): ?array
{
    return test()->withHeaders(['X-Agent-Key' => $apiKey])->getJson('/api/commands/pending')->assertSuccessful()->json('command');
}

function createSophie(Device $device, User $user): StaffAccount
{
    return app(CreateStaffAccount::class)(
        new StaffAccountDetails(username: 'sophie', fullName: 'Sophie Smith', isAdmin: false, isEnabled: true, deviceIds: [$device->id]),
        $user,
    );
}

it('hands the agent the temp password from config only when it fetches the sync', function (): void {
    $account = createSophie($this->device, $this->user);

    expect(pullStaffCommand()['parameters'])->toBe([
        'StaffAccountId' => (string) $account->id,
        'Username' => 'sophie',
        'FullName' => 'Sophie Smith',
        'IsAdmin' => 'false',
        'IsEnabled' => 'true',
        'ResetPassword' => 'true',
        'StaffTempPassword' => config('staff.temp_password'),
    ])->and(DeviceCommand::query()->sole()->parameters)->not->toHaveKey('StaffTempPassword');
});

it('keeps no staff password anywhere', function (): void {
    expect(Schema::hasColumn('staff_accounts', 'password'))->toBeFalse()
        ->and(config('scripts.system.sync-local-user.secrets'))->not->toContain('StaffPassword')
        ->and(StaffAccount::factory()->create()->toArray())->not->toHaveKey('password');
});

it('hands a blank temp password to a sync whose account was deleted, so it is never recreated', function (): void {
    createSophie($this->device, $this->user)->delete();

    expect(pullStaffCommand()['parameters']['StaffTempPassword'])->toBe('');
});

it('hands a blank temp password to a sync naming a different username than the account', function (): void {
    $account = StaffAccount::factory()->create(['username' => 'sophie']);
    $account->devices()->attach($this->device);
    app(SyncStaffAccount::class)($account, $this->user);
    $command = DeviceCommand::query()->sole();
    $command->update(['parameters' => [...$command->parameters, 'Username' => 'administrator']]);

    expect(pullStaffCommand()['parameters']['StaffTempPassword'])->toBe('');
});

it('never hands the temp password to an ad-hoc command carrying the same parameters', function (): void {
    $account = createSophie($this->device, $this->user);
    DeviceCommand::query()->sole()->delete();

    DeviceCommand::query()->create([
        'device_id' => $this->device->id,
        'script_content' => 'Get-ChildItem env:',
        'script_type' => 'powershell',
        'parameters' => ['StaffAccountId' => (string) $account->id, 'Username' => 'sophie'],
        'status' => 'pending',
        'queued_at' => now(),
        'queued_by' => $this->user->id,
        'timeout_seconds' => 60,
    ]);

    expect(pullStaffCommand()['parameters'])->not->toHaveKey('StaffTempPassword');
});
