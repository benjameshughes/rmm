<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Livewire\Staff\Index;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceGroup;
use App\Models\Script;
use App\Models\StaffAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();

    $this->user = User::factory()->create();
    $this->device = Device::factory()->windows()->active()->create(['agent_version' => '0.7.1', 'hostname' => 'OFFICE-PC1']);
});

it('is routed and in the nav', function (): void {
    $this->actingAs($this->user)
        ->get(route('staff.index'))
        ->assertSuccessful()
        ->assertSeeLivewire(Index::class)
        ->assertSee(route('staff.index'));
});

it('lists accounts with their targets and each PC\'s latest sync', function (): void {
    $group = DeviceGroup::factory()->create(['name' => 'Warehouse']);
    $account = StaffAccount::factory()->admin()->create(['username' => 'sophie', 'full_name' => 'Sophie Smith']);
    $account->deviceGroups()->attach($group);

    DeviceCommand::factory()->for($this->device)->create([
        'script_id' => Script::findSystem('sync-local-user')->id,
        'parameters' => ['StaffAccountId' => (string) $account->id, 'Username' => 'sophie'],
        'status' => CommandStatus::Completed,
    ]);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->assertSee('sophie')
        ->assertSee('Sophie Smith')
        ->assertSee('Warehouse')
        ->assertSee('OFFICE-PC1: '.CommandStatus::Completed->label());
});

it('creates an account from the modal and queues its sync', function (): void {
    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('create')
        ->assertSet('showModal', true)
        ->assertNotSet('password', '')
        ->set('username', 'sophie')
        ->set('fullName', 'Sophie Smith')
        ->set('isAdmin', true)
        ->set('deviceIds', [(string) $this->device->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showModal', false);

    $account = StaffAccount::query()->sole();

    expect($account->is_admin)->toBeTrue()
        ->and($account->devices->pluck('id')->all())->toBe([$this->device->id])
        ->and(DeviceCommand::query()->sole()->parameters['Username'])->toBe('sophie');
});

it('refuses a bad username, a short password and a taken username', function (): void {
    StaffAccount::factory()->create(['username' => 'taken']);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->set('username', 'bad name!')
        ->set('fullName', 'Someone')
        ->set('password', 'short')
        ->call('save')
        ->assertHasErrors(['username' => 'regex', 'password' => 'min'])
        ->set('username', 'taken')
        ->call('save')
        ->assertHasErrors(['username' => 'unique']);
});

it('edits an account, keeping the password when left blank, and reveals it on request', function (): void {
    $account = StaffAccount::factory()->create(['username' => 'sophie', 'password' => 'kept-password-1']);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('edit', $account->id)
        ->assertSet('username', 'sophie')
        ->assertSet('password', '')
        ->call('revealPassword')
        ->assertSet('password', 'kept-password-1')
        ->set('password', '')
        ->set('fullName', 'Sophie Jones')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->full_name)->toBe('Sophie Jones')
        ->and($account->fresh()->password)->toBe('kept-password-1');
});

it('resets, disables and deletes from the row buttons', function (): void {
    $account = StaffAccount::factory()->create(['password' => 'old-password-12']);
    $account->devices()->attach($this->device);

    $page = Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call('resetAccountPassword', $account->id)
        ->assertSet('showResetPassword', true);

    expect($page->get('resetPassword'))->toBe($account->fresh()->password)->not->toBe('old-password-12');

    $page->call('disable', $account->id);
    expect($account->fresh()->is_enabled)->toBeFalse();

    $page->call('delete', $account->id);
    expect(StaffAccount::query()->exists())->toBeFalse()
        ->and(DeviceCommand::query()->where('script_id', Script::findSystem('remove-local-user')->id)->count())->toBe(1);
});

it('queues the screen lock to staff PCs', function (): void {
    StaffAccount::factory()->create()->devices()->attach($this->device);

    Livewire::actingAs($this->user)->test(Index::class)->call('lockScreens');

    expect(DeviceCommand::query()->sole()->script->slug)->toBe('set-screen-lock');
});

it('authorises every mutating action', function (string $method, string $ability): void {
    $account = StaffAccount::factory()->create();
    Gate::before(fn (User $user, string $checked): ?bool => $checked === $ability ? false : null);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->call($method, ...($method === 'lockScreens' ? [] : [$account->id]))
        ->assertForbidden();
})->with([
    'sync' => ['sync', 'update'],
    'edit' => ['edit', 'update'],
    'reset' => ['resetAccountPassword', 'update'],
    'disable' => ['disable', 'update'],
    'delete' => ['delete', 'delete'],
    'screen lock' => ['lockScreens', 'lock-staff-screens'],
]);

it('refreshes the sync results when a command updates over Reverb', function (): void {
    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->dispatch('echo-private:devices,CommandUpdated', ['commandId' => 1])
        ->assertSuccessful();
});
