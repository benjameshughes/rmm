<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Livewire\Devices\BackupCredentials;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'OFFICE-PC-07']);
});

it('suggests the lowercase hostname and shows both passwords as not set', function (): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->assertSet('restUsername', 'office-pc-07')
        ->assertSet('restPassword', '')
        ->assertSeeInOrder(['Rest-server password', 'Not set', 'Repository password', 'Not set']);
});

it('stores the credentials encrypted, clears the form and never echoes a password back', function (): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->set('restPassword', 'rest-pass-0123456789')
        ->set('repositoryPassword', 'repo-pass-0123456789')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('restPassword', '')
        ->assertSet('repositoryPassword', '')
        ->assertDispatched('backup-credentials-saved')
        ->assertDontSee('rest-pass-0123456789')
        ->assertDontSee('repo-pass-0123456789')
        ->assertSeeInOrder(['Rest-server password', 'Set', 'Repository password', 'Set']);

    $device = $this->device->fresh();
    $raw = DB::table('devices')->where('id', $device->id)->first();

    expect($device)
        ->backup_rest_username->toBe('office-pc-07')
        ->backup_rest_password->toBe('rest-pass-0123456789')
        ->backup_repository_password->toBe('repo-pass-0123456789')
        ->backup_configured_at->not->toBeNull()
        ->and($raw->backup_rest_password)->not->toContain('rest-pass')
        ->and($raw->backup_repository_password)->not->toContain('repo-pass');
});

it('audits the change by name only', function (): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->set('restPassword', 'rest-pass-0123456789')
        ->set('repositoryPassword', 'repo-pass-0123456789')
        ->call('save');

    $audit = AuditLog::query()->where('action', AuditAction::DeviceUpdated)->sole();

    expect($audit->properties['secrets_changed'])->toBe(['backup_rest_password', 'backup_repository_password'])
        ->and($audit->properties['changes'])->toBe(['backup_rest_username' => ['from' => null, 'to' => 'office-pc-07']])
        ->and(json_encode($audit->properties))->not->toContain('pass-0123456789');
});

it('keeps a password left blank once credentials are set, so the username can change alone', function (): void {
    $device = Device::factory()->active()->withBackupCredentials(setHoursAgo: 48)->create();
    $before = [$device->backup_rest_password, $device->backup_repository_password, $device->backup_configured_at->toIso8601String()];

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $device])
        ->assertSeeInOrder(['Rest-server password', 'Set', 'Repository password', 'Set'])
        ->set('restUsername', 'renamed-pc')
        ->call('save')
        ->assertHasNoErrors();

    $device->refresh();

    expect($device->backup_rest_username)->toBe('renamed-pc')
        ->and([$device->backup_rest_password, $device->backup_repository_password, $device->backup_configured_at->toIso8601String()])->toBe($before);
});

it('refuses bad credentials with readable messages', function (string $field, string $value, string $rule): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->set('restPassword', 'rest-pass-0123456789')
        ->set('repositoryPassword', 'repo-pass-0123456789')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field => $rule]);

    expect($this->device->fresh()->backup_configured_at)->toBeNull();
})->with([
    'no rest password the first time' => ['restPassword', '', 'required'],
    'no repository password the first time' => ['repositoryPassword', '', 'required'],
    'short password' => ['restPassword', 'short', 'min'],
    'line break in password' => ['repositoryPassword', "pass\nword-0123456", 'not_regex'],
    'uppercase username' => ['restUsername', 'OFFICE-PC-07', 'regex'],
    'username with a slash' => ['restUsername', 'office/pc', 'regex'],
]);

it('forbids setting credentials on a monitor-only device', function (): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $device])
        ->set('restPassword', 'rest-pass-0123456789')
        ->set('repositoryPassword', 'repo-pass-0123456789')
        ->call('save')
        ->assertForbidden();
});
