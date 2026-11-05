<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceLocalAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->device = Device::factory()->windows()->active()->withApiKey('PC-KEY')->create(['hostname' => 'office-pc1']);
});

/**
 * @return array<string, mixed>
 */
function localAccount(array $overrides = []): array
{
    return [
        'name' => 'sophie',
        'full_name' => 'Sophie Smith',
        'sid' => 'S-1-5-21-111-222-333-1001',
        'is_enabled' => true,
        'is_admin' => false,
        'last_logon_at' => '2026-11-04T08:15:00Z',
        'is_signed_in' => true,
        ...$overrides,
    ];
}

function postAccounts(?array $accounts, string $key = 'PC-KEY'): TestResponse
{
    $payload = ['agent_version' => '0.11.0', 'cpu' => ['usage_percent' => 5]];

    if ($accounts !== null) {
        $payload['local_accounts'] = $accounts;
    }

    return test()->withHeaders(['X-Device-Key' => $key])->postJson('/api/metrics', $payload);
}

it('stores the local accounts a Windows agent reports', function (): void {
    postAccounts([
        localAccount(),
        localAccount(['name' => 'Administrator', 'full_name' => null, 'sid' => 'S-1-5-21-111-222-333-500', 'is_enabled' => false, 'is_admin' => true, 'last_logon_at' => null, 'is_signed_in' => false]),
    ])->assertSuccessful();

    $accounts = $this->device->localAccounts()->orderBy('username')->get();

    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->username)->toBe('Administrator')
        ->and($accounts[0]->is_admin)->toBeTrue()
        ->and($accounts[0]->is_enabled)->toBeFalse()
        ->and($accounts[0]->last_logon_at)->toBeNull()
        ->and($accounts[1]->full_name)->toBe('Sophie Smith')
        ->and($accounts[1]->is_signed_in)->toBeTrue()
        ->and($accounts[1]->last_logon_at->toIso8601ZuluString())->toBe('2026-11-04T08:15:00Z');
});

it('replaces the accounts on every report, keyed by SID', function (): void {
    postAccounts([localAccount(), localAccount(['name' => 'temp', 'sid' => 'S-1-5-21-111-222-333-1002'])]);
    $sophieId = DeviceLocalAccount::query()->where('username', 'sophie')->value('id');

    postAccounts([localAccount(['name' => 'sophie.smith', 'is_signed_in' => false])])->assertSuccessful();

    $accounts = $this->device->localAccounts()->get();

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->id)->toBe($sophieId)
        ->and($accounts[0]->username)->toBe('sophie.smith')
        ->and($accounts[0]->is_signed_in)->toBeFalse();
});

it('keeps unknown admin and sign-in readings as unknown, not false', function (): void {
    postAccounts([localAccount(['is_admin' => null, 'is_signed_in' => null])])->assertSuccessful();

    $account = $this->device->localAccounts()->sole();

    expect($account->is_admin)->toBeNull()
        ->and($account->is_signed_in)->toBeNull();
});

it('leaves the accounts alone when an older agent sends none', function (): void {
    DeviceLocalAccount::factory()->for($this->device)->create(['username' => 'sophie']);

    postAccounts(null)->assertSuccessful();

    expect($this->device->localAccounts()->count())->toBe(1);
});

it('clears the accounts when the agent reports an empty list', function (): void {
    DeviceLocalAccount::factory()->for($this->device)->create();

    postAccounts([])->assertSuccessful();

    expect($this->device->localAccounts()->count())->toBe(0);
});

it('ignores local accounts from a Linux agent', function (): void {
    Device::factory()->active()->monitorOnly()->withApiKey('LINUX-KEY')->create(['hostname' => 'web1']);

    test()->withHeaders(['X-Device-Key' => 'LINUX-KEY'])->postJson('/api/metrics', [
        'monitor_only' => true,
        'agent_version' => '0.11.0',
        'cpu' => ['usage_percent' => 5],
        'local_accounts' => [localAccount()],
    ])->assertSuccessful();

    expect(DeviceLocalAccount::query()->count())->toBe(0);
});

it('refuses malformed accounts', function (array $account, string $field): void {
    postAccounts([localAccount($account)])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no SID' => [['sid' => null], 'local_accounts.0.sid'],
    'not a SID' => [['sid' => 'Administrator'], 'local_accounts.0.sid'],
    'no name' => [['name' => ''], 'local_accounts.0.name'],
    'enabled not boolean' => [['is_enabled' => 'maybe'], 'local_accounts.0.is_enabled'],
    'bad logon time' => [['last_logon_at' => 'yesterday-ish'], 'local_accounts.0.last_logon_at'],
]);

it('flags the built-in accounts by their well-known RID', function (string $sid, bool $isBuiltIn): void {
    $account = DeviceLocalAccount::factory()->for($this->device)->create(['sid' => $sid]);

    expect($account->isBuiltIn)->toBe($isBuiltIn);
})->with([
    'Administrator' => ['S-1-5-21-111-222-333-500', true],
    'Guest' => ['S-1-5-21-111-222-333-501', true],
    'DefaultAccount' => ['S-1-5-21-111-222-333-503', true],
    'WDAGUtilityAccount' => ['S-1-5-21-111-222-333-504', true],
    'a person' => ['S-1-5-21-111-222-333-1001', false],
    'RID ending in 500' => ['S-1-5-21-111-222-333-1500', false],
]);
