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

it('offers to enable backups on a PC that has none, with the APP_KEY recovery note', function (): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->assertSee('Enable backups')
        ->assertSee('Keep APP_KEY in Bitwarden')
        ->assertDontSee('data-disable-backups', false);
});

it('names the repository after the lowercase hostname and generates an encrypted password it never echoes', function (): void {
    $component = Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])
        ->call('enable')
        ->assertHasNoErrors()
        ->assertDispatched('backup-credentials-saved')
        ->assertSee('office-pc-07')
        ->assertSee('Disable backups');

    $device = $this->device->fresh();
    $raw = DB::table('devices')->where('id', $device->id)->first();

    expect($device)
        ->backup_repository_name->toBe('office-pc-07')
        ->backup_repository_password->toMatch('/^[A-Za-z0-9]{48}$/')
        ->backup_configured_at->not->toBeNull()
        ->and($raw->backup_repository_password)->not->toContain($device->backup_repository_password);

    $component->assertDontSee($device->backup_repository_password);
});

it('generates a different repository password for each PC', function (): void {
    $other = Device::factory()->active()->windows()->create();

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])->call('enable');
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $other])->call('enable');

    expect($this->device->fresh()->backup_repository_password)->not->toBe($other->fresh()->backup_repository_password);
});

it('audits enabling by name only', function (): void {
    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])->call('enable');

    $device = $this->device->fresh();
    $audit = AuditLog::query()->where('action', AuditAction::DeviceUpdated)->sole();

    expect($audit->properties['secrets_changed'])->toBe(['backup_repository_password'])
        ->and($audit->properties['changes'])->toBe(['backup_repository_name' => ['from' => null, 'to' => 'office-pc-07']])
        ->and(json_encode($audit->properties))->not->toContain($device->backup_repository_password);
});

it('never changes the repository password or name once set, through enable, disable and enable again', function (): void {
    $component = Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $this->device])->call('enable');
    $before = $this->device->fresh()->only('backup_repository_name', 'backup_repository_password');

    $this->device->forceFill(['hostname' => 'RENAMED-PC'])->save();
    $component->call('enable')->call('disable')->call('enable');

    expect($this->device->fresh())
        ->only('backup_repository_name', 'backup_repository_password')->toBe($before)
        ->backup_configured_at->not->toBeNull();
});

it('disables backups, keeping the repository password so enabling again reuses the repository', function (): void {
    $device = Device::factory()->active()->withBackupCredentials(setHoursAgo: 48)->create();
    $before = $device->only('backup_repository_name', 'backup_repository_password');

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $device])
        ->call('disable')
        ->assertDispatched('backup-credentials-saved')
        ->assertSee('Enable backups')
        ->assertDontSee('data-disable-backups', false);

    $device->refresh();

    expect($device)
        ->backup_configured_at->toBeNull()
        ->hasBackupCredentials->toBeFalse()
        ->canBackUp()->toBeFalse()
        ->only('backup_repository_name', 'backup_repository_password')->toBe($before);
});

it('forbids managing backups on a monitor-only device', function (string $action): void {
    $device = Device::factory()->active()->monitorOnly()->create();

    Livewire::actingAs($this->user)->test(BackupCredentials::class, ['device' => $device])
        ->call($action)
        ->assertForbidden();

    expect($device->fresh()->backup_repository_password)->toBeNull();
})->with(['enable', 'disable']);
