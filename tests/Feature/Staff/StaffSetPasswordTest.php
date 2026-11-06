<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Actions\Staff\SetStaffPassword;
use App\Enums\AuditAction;
use App\Enums\CommandStatus;
use App\Livewire\Staff\Index;
use App\Models\AuditLog;
use App\Models\CommandSecret;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

const STAFF_CHOSEN_PASSWORD = 'Corby-Horse-Battery-77';

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->device = Device::factory()->windows()->active()->withApiKey('PW-PC-KEY')->create(['agent_version' => '0.7.1', 'hostname' => 'OFFICE-PC1']);
    $this->account = StaffAccount::factory()->admin()->create(['username' => 'ben']);
    $this->account->devices()->attach($this->device);
});

function pullPasswordCommand(string $apiKey = 'PW-PC-KEY'): ?array
{
    return test()->withHeaders(['X-Agent-Key' => $apiKey])->getJson('/api/commands/pending')->assertSuccessful()->json('command');
}

function setChosenPassword(): int
{
    return app(SetStaffPassword::class)(test()->account, STAFF_CHOSEN_PASSWORD, test()->user);
}

/** Every row of the tables a password could leak into, as one string. */
function storedForStaffPassword(): string
{
    return collect(['device_commands', 'command_secrets', 'audit_logs', 'scripts'])
        ->map(fn (string $table): string => DB::table($table)->get()->toJson())
        ->implode("\n");
}

it('queues one set-local-password per targeted PC, with no password in its parameters', function (): void {
    $second = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1']);
    $this->account->devices()->attach($second);

    expect(setChosenPassword())->toBe(2)
        ->and(DeviceCommand::query()->where('script_id', Script::findSystem('set-local-password')->id)->count())->toBe(2)
        ->and(DeviceCommand::query()->first()->parameters)->toBe(['StaffAccountId' => (string) $this->account->id, 'Username' => 'ben'])
        ->and(CommandSecret::query()->count())->toBe(2);
});

it('never stores, audits or logs the password in plain text', function (): void {
    $logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logged->push($event->message.json_encode($event->context)));

    setChosenPassword();

    expect(storedForStaffPassword())->not->toContain(STAFF_CHOSEN_PASSWORD)
        ->and(CommandSecret::query()->sole()->value)->toBe(STAFF_CHOSEN_PASSWORD)
        ->and(CommandSecret::query()->sole()->toArray())->not->toHaveKey('value')
        ->and(AuditLog::query()->where('action', AuditAction::StaffAccountPasswordSet)->count())->toBe(1);

    expect($logged)->not->toBeEmpty()->and($logged->implode("\n"))->not->toContain(STAFF_CHOSEN_PASSWORD);
});

it('hands the password over once at fetch and then forgets it', function (): void {
    setChosenPassword();

    expect(pullPasswordCommand()['parameters']['StaffPassword'])->toBe(STAFF_CHOSEN_PASSWORD)
        ->and(CommandSecret::query()->count())->toBe(0);

    DeviceCommand::query()->sole()->requeue();

    expect(pullPasswordCommand()['parameters']['StaffPassword'])->toBe('');
});

it('hands over a blank password once the staff account is deleted', function (): void {
    setChosenPassword();
    $this->account->delete();

    expect(pullPasswordCommand()['parameters']['StaffPassword'])->toBe('')
        ->and(CommandSecret::query()->count())->toBe(0);
});

it('wipes the password when the command is cancelled, fails or times out', function (string $finish): void {
    setChosenPassword();
    $command = DeviceCommand::query()->sole();

    match ($finish) {
        'cancel' => $command->cancel(),
        'fail' => $command->markAsFailed('Agent crashed'),
        'timeout' => $command->markAsTimedOut(),
    };

    expect(CommandSecret::query()->count())->toBe(0);
})->with(['cancel', 'fail', 'timeout']);

it('prunes a password never fetched once it expires, and one whose command finished', function (): void {
    setChosenPassword();
    $finished = DeviceCommand::factory()->for($this->device)->create(['status' => CommandStatus::Completed]);
    DB::table('command_secrets')->insert([
        'device_command_id' => $finished->id, 'name' => 'StaffPassword', 'value' => encrypt('x', false), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('model:prune', ['--model' => [CommandSecret::class]]);
    expect(CommandSecret::query()->count())->toBe(1);

    $this->travel((int) config('staff.password_handover_ttl_minutes') + 1)->minutes();
    Artisan::call('model:prune', ['--model' => [CommandSecret::class]]);

    expect(CommandSecret::query()->count())->toBe(0);
});

it('hands over a blank password once it has expired', function (): void {
    setChosenPassword();
    $this->travel((int) config('staff.password_handover_ttl_minutes') + 1)->minutes();

    expect(pullPasswordCommand()['parameters']['StaffPassword'])->toBe('');
});

it('sets the password from the modal and clears it from the component', function (): void {
    Livewire::test(Index::class)
        ->call('choosePassword', $this->account->id)
        ->assertSet('showPasswordModal', true)
        ->set('password', STAFF_CHOSEN_PASSWORD)
        ->set('passwordConfirmation', STAFF_CHOSEN_PASSWORD)
        ->call('setAccountPassword')
        ->assertHasNoErrors()
        ->assertSet('password', '')
        ->assertSet('passwordConfirmation', '')
        ->assertSet('showPasswordModal', false);

    expect(CommandSecret::query()->count())->toBe(1);
});

it('validates the password', function (string $password, string $confirmation, string $field, string $rule): void {
    Livewire::test(Index::class)
        ->call('choosePassword', $this->account->id)
        ->set('password', $password)
        ->set('passwordConfirmation', $confirmation)
        ->call('setAccountPassword')
        ->assertHasErrors([$field => $rule]);

    expect(DeviceCommand::query()->count())->toBe(0);
})->with([
    'missing' => ['', '', 'password', 'required'],
    'too short' => ['short', 'short', 'password', 'min'],
    'mismatch' => [STAFF_CHOSEN_PASSWORD, 'something-else-entirely', 'passwordConfirmation', 'same'],
]);

it('refuses a user the policy does not let update the account', function (): void {
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'setPassword' ? false : null);

    Livewire::test(Index::class)
        ->call('choosePassword', $this->account->id)
        ->assertForbidden();
});

it('refuses to set the password when the policy denies it after the modal opened', function (): void {
    $component = Livewire::test(Index::class)->call('choosePassword', $this->account->id)
        ->set('password', STAFF_CHOSEN_PASSWORD)
        ->set('passwordConfirmation', STAFF_CHOSEN_PASSWORD);

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'setPassword' ? false : null);

    $component->call('setAccountPassword')->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});

it('syncs set-local-password as an internal script that never prints the password or forces a change', function (): void {
    $script = Script::findSystem('set-local-password');

    expect($script->is_internal)->toBeTrue()
        ->and($script->secretNames())->toBe(['StaffPassword'])
        ->and($script->parameters->pluck('name')->all())->toBe(['StaffAccountId', 'Username'])
        ->and($script->script_content)->toContain('$env:RMM_StaffPassword')
        ->toContain('Write-Output "ATTENTION:')
        ->toContain('does not exist on this PC')
        ->not->toContain('logonpasswordchg:yes')
        ->not->toContain('-PasswordNeverExpires')
        ->not->toContain('Stop-Run')
        ->not->toMatch('/Write-(Output|Host|Verbose|Information)[^\r\n]*\$password/i');
});

it('is for admin accounts only, so a standard user is refused and shown no button', function (): void {
    $standard = StaffAccount::factory()->create(['username' => 'sophie', 'is_admin' => false]);
    $standard->devices()->attach($this->device);

    Livewire::test(Index::class)
        ->assertSeeHtml('wire:click="choosePassword('.$this->account->id.')"')
        ->assertDontSeeHtml('wire:click="choosePassword('.$standard->id.')"')
        ->call('choosePassword', $standard->id)
        ->assertForbidden();

    expect(DeviceCommand::query()->count())->toBe(0);
});
