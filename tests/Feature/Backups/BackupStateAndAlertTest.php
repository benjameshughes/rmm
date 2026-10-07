<?php

declare(strict_types=1);

use App\Actions\Backup\SyncBackupAlert;
use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\BackupRunStatus;
use App\Enums\BackupState;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;

beforeEach(function (): void {
    config(['backup.stale_after_hours' => 26]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function backupDevice(array $attributes = [], int $configuredHoursAgo = 100): Device
{
    $device = Device::factory()->active()->withBackupCredentials(setHoursAgo: $configuredHoursAgo)->create();
    $device->forceFill($attributes)->save();

    return $device;
}

it('works out where a PC stands', function (Closure $device, BackupState $state): void {
    expect($device()->backupState())->toBe($state);
})->with([
    'no credentials' => [fn (): Device => Device::factory()->active()->windows()->create(['last_good_backup_at' => now()]), BackupState::NotConfigured],
    'just set up' => [fn (): Device => backupDevice(configuredHoursAgo: 2), BackupState::NeverBackedUp],
    'set up long ago, never backed up' => [fn (): Device => backupDevice(configuredHoursAgo: 27), BackupState::Stale],
    'backed up an hour ago' => [fn (): Device => backupDevice(['last_good_backup_at' => now()->subHour(), 'last_backup_at' => now()->subHour(), 'last_backup_status' => BackupRunStatus::Succeeded]), BackupState::Healthy],
    'skipped files an hour ago' => [fn (): Device => backupDevice(['last_good_backup_at' => now()->subHour(), 'last_backup_at' => now()->subHour(), 'last_backup_status' => BackupRunStatus::Partial]), BackupState::Partial],
    'last good backup 27 hours ago' => [fn (): Device => backupDevice(['last_good_backup_at' => now()->subHours(27), 'last_backup_at' => now()->subHours(27), 'last_backup_status' => BackupRunStatus::Succeeded]), BackupState::Stale],
    'old partial backup' => [fn (): Device => backupDevice(['last_good_backup_at' => now()->subHours(30), 'last_backup_at' => now()->subHours(30), 'last_backup_status' => BackupRunStatus::Partial]), BackupState::Stale],
    'failed after a recent good one' => [fn (): Device => backupDevice(['last_good_backup_at' => now()->subHours(2), 'last_backup_at' => now()->subHour(), 'last_backup_status' => BackupRunStatus::Failed]), BackupState::Failed],
    'never succeeded, last run failed' => [fn (): Device => backupDevice(['last_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Failed], configuredHoursAgo: 1), BackupState::Failed],
]);

it('only flags overdue and failed PCs as needing attention, and partial ones on a badge', function (): void {
    expect(collect(BackupState::cases())->filter->needsAttention()->values()->all())->toBe([BackupState::Stale, BackupState::Failed])
        ->and(collect(BackupState::cases())->filter->isWorthFlagging()->values()->all())->toBe([BackupState::Partial, BackupState::Stale, BackupState::Failed])
        ->and(collect(BackupState::cases())->map->color()->unique()->count())->toBeGreaterThan(3);
});

it('raises one alert for an overdue PC and keeps its message current', function (): void {
    $device = backupDevice(['last_good_backup_at' => now()->subHours(30), 'last_backup_status' => BackupRunStatus::Succeeded]);

    app(SyncBackupAlert::class)($device);
    app(SyncBackupAlert::class)($device);

    $alert = Alert::query()->sole();

    expect($alert->metric)->toBe(AlertMetric::BackupOverdue)
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->message)->toBe("{$device->hostname}: last good backup 1 day ago")
        ->and($alert->alertRule->name)->toBe(config('backup.alert.rule_name'));
});

it('raises the alert for a failed run and resolves it once healthy', function (): void {
    $device = backupDevice(['last_good_backup_at' => now()->subHours(2), 'last_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Failed]);

    app(SyncBackupAlert::class)($device);
    expect(Alert::query()->sole()->message)->toContain('last backup failed');

    $device->forceFill(['last_good_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Succeeded])->save();
    app(SyncBackupAlert::class)($device);

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('never alerts for a PC without credentials or one that is fine', function (): void {
    $bare = Device::factory()->active()->windows()->create();
    $healthy = backupDevice(['last_good_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Succeeded]);
    $partial = backupDevice(['last_good_backup_at' => now(), 'last_backup_status' => BackupRunStatus::Partial]);

    collect([$bare, $healthy, $partial])->each(fn (Device $device) => app(SyncBackupAlert::class)($device));

    expect(Alert::query()->count())->toBe(0);
});

it('raises nothing new while the built-in rule is switched off', function (): void {
    AlertRule::backupOverdue()->update(['is_active' => false]);

    app(SyncBackupAlert::class)(backupDevice(configuredHoursAgo: 50));

    expect(Alert::query()->count())->toBe(0);
});

it('checks every PC with credentials hourly, offline ones included', function (): void {
    $stale = backupDevice(['last_good_backup_at' => now()->subDays(3), 'last_seen' => now()->subDays(3)]);
    $healthy = backupDevice(['last_good_backup_at' => now()]);
    Device::factory()->active()->windows()->create();

    $this->artisan('backups:check')->assertSuccessful();

    expect(Alert::query()->pluck('device_id')->all())->toBe([$stale->id]);

    $stale->forceFill(['last_good_backup_at' => now()])->save();
    $this->artisan('backups:check')->assertSuccessful();

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved)
        ->and($healthy->unresolvedAlerts()->count())->toBe(0);
});

it('is not a threshold metric users build rules around', function (): void {
    expect(AlertMetric::BackupOverdue->isThresholdBased())->toBeFalse()
        ->and(AlertMetric::thresholdBased())->not->toContain(AlertMetric::BackupOverdue);
});
