<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Actions\Staff\CreateStaffAccount;
use App\Actions\Staff\ResetStaffPassword;
use App\DTOs\Staff\StaffAccountDetails;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

function createSophie(Device $device, User $user, string $password = 'correct-horse-battery'): StaffAccount
{
    return app(CreateStaffAccount::class)(
        new StaffAccountDetails(username: 'sophie', fullName: 'Sophie Smith', isAdmin: false, isEnabled: true, deviceIds: [$device->id]),
        $password,
        $user,
    );
}

it('hands the agent the staff password only when it fetches the sync', function (): void {
    $account = createSophie($this->device, $this->user);

    expect(pullStaffCommand()['parameters'])->toBe([
        'StaffAccountId' => (string) $account->id,
        'Username' => 'sophie',
        'FullName' => 'Sophie Smith',
        'IsAdmin' => 'false',
        'IsEnabled' => 'true',
        'StaffPassword' => 'correct-horse-battery',
    ]);
});

it('never stores the password in device_commands, their parameters, the audit log or the logs', function (): void {
    $logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $message) => $logged->push($message->message.json_encode($message->context)));

    $account = createSophie($this->device, $this->user);
    app(ResetStaffPassword::class)($account, $this->user, 'another-long-password');
    pullStaffCommand();

    $stored = collect(['device_commands', 'audit_logs'])
        ->map(fn (string $table): string => DB::table($table)->get()->toJson())
        ->implode("\n");

    expect(DeviceCommand::query()->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'staff_account.password_changed')->exists())->toBeTrue()
        ->and($stored)->not->toContain('correct-horse-battery')
        ->not->toContain('another-long-password')
        ->and(DeviceCommand::query()->get()->pluck('parameters')->toJson())->not->toContain('StaffPassword')
        ->and($logged->implode("\n"))->not->toContain('another-long-password')
        ->and(DB::table('staff_accounts')->value('password'))->not->toContain('another-long-password');
});

it('keeps the password out of the account when serialised', function (): void {
    expect(StaffAccount::factory()->create()->toArray())->not->toHaveKey('password');
});

it('hands a blank password to a sync whose account was deleted', function (): void {
    createSophie($this->device, $this->user)->delete();

    expect(pullStaffCommand()['parameters']['StaffPassword'])->toBe('');
});

it('hands a blank password to a sync naming a different username than the account', function (): void {
    createSophie($this->device, $this->user);
    $command = DeviceCommand::query()->sole();
    $command->update(['parameters' => [...$command->parameters, 'Username' => 'administrator']]);

    expect(pullStaffCommand()['parameters']['StaffPassword'])->toBe('');
});

it('never hands the password to an ad-hoc command carrying the same parameters', function (): void {
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

    expect(pullStaffCommand()['parameters'])->not->toHaveKey('StaffPassword');
});
