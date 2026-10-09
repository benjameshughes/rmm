<?php

declare(strict_types=1);

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Enums\BackupRunStatus;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Devices\ServerBackups;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceBackupSnapshot;
use App\Models\ServerBackupJob;
use App\Models\ServerBackupSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->server = Device::factory()->active()->monitorOnly()->create(['hostname' => 'achcto', 'last_seen' => now()]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function serverJobWithSnapshots(Device $device, string $name, int $snapshots = 3, array $attributes = []): ServerBackupJob
{
    $job = ServerBackupJob::factory()->create(['device_id' => $device->id, 'job' => $name, ...$attributes]);

    collect(range(0, $snapshots - 1))->each(fn (int $hour) => ServerBackupSnapshot::factory()->create([
        'server_backup_job_id' => $job->id,
        'taken_at' => now()->subHours($hour),
        'short_id' => sprintf('%s%04d', substr($name, 0, 4), $hour),
    ]));

    return $job;
}

it('shows each job with its health, stats, charts and snapshot history on the Backups tab', function (): void {
    serverJobWithSnapshots($this->server, 'forgejo', snapshots: 3, attributes: ['snapshot_count' => 1606, 'total_size' => 3 * 1024 ** 3, 'compression_ratio' => 2.1, 'compression_space_saving' => 52.0]);
    serverJobWithSnapshots($this->server, 'mariadb', snapshots: 2, attributes: ['latest_bytes_processed' => 0, 'baseline_bytes_processed' => 1_200_000_000]);

    $this->actingAs($this->user)
        ->get(route('devices.backups', $this->server))
        ->assertSuccessful()
        ->assertSee('achcto · Backups', false)
        ->assertSee('data-server-backups-attention="1"', false)
        ->assertSeeInOrder(['data-server-backup-job="mariadb"', 'Latest snapshot backed up 0 B', 'data-server-backup-job="forgejo"'], false)
        ->assertSee('data-server-backup-health="shrunk"', false)
        ->assertSee('sftp:scarif:/mnt/scarif/data/backups', false)
        ->assertSee('1,606')
        ->assertSee('3 GB')
        ->assertSee('2.1x compression, 52% saved')
        ->assertSee('The RMM has 3 of the 1,606')
        ->assertSee('Size backed up')
        ->assertSee('Data added')
        ->assertSee('forg0000')
        ->assertSee('/all-databases.sql')
        ->assertDontSee('Back up now')
        ->assertDontSee('wire:poll', false);
});

it('pages through a long snapshot history', function (): void {
    config(['backup.servers.snapshots_per_page' => 2]);
    $job = serverJobWithSnapshots($this->server, 'mariadb', snapshots: 3);

    Livewire::actingAs($this->user)->test(ServerBackups::class, ['device' => $this->server])
        ->assertSee('mari0000')
        ->assertDontSee('mari0002')
        ->call('setPage', 2, "snapshots-{$job->id}")
        ->assertSee('mari0002')
        ->assertDontSee('mari0000');
});

it('says how to start when the server reports no status files', function (): void {
    $this->actingAs($this->user)
        ->get(route('devices.backups', $this->server))
        ->assertSee('data-server-backups-empty', false)
        ->assertSee('No backup status files yet. Add the status snippet to the backup script');
});

it('redraws when the server reports a changed status file', function (): void {
    $component = Livewire::actingAs($this->user)->test(ServerBackups::class, ['device' => $this->server])
        ->assertSee('data-server-backups-empty', false);

    serverJobWithSnapshots($this->server, 'cantina');

    $component->dispatch("echo-private:devices.{$this->server->id},ServerBackupsReported", ['deviceId' => $this->server->id])
        ->assertSee('data-server-backup-job="cantina"', false);
});

it('forgets a job whose status file went missing, and resolves its alert', function (): void {
    $job = serverJobWithSnapshots($this->server, 'oldjob', attributes: ['last_reported_at' => now()->subDays(5)]);
    $alert = Alert::factory()->create(['device_id' => $this->server->id, 'metric' => AlertMetric::ServerBackupProblem, 'subject' => 'oldjob', 'status' => AlertStatus::Triggered, 'resolved_at' => null]);

    Livewire::actingAs($this->user)->test(ServerBackups::class, ['device' => $this->server])
        ->assertSee('data-forget-server-backup-job', false)
        ->call('forget', $job->id)
        ->assertHasNoErrors();

    expect(ServerBackupJob::query()->count())->toBe(0)
        ->and(ServerBackupSnapshot::query()->count())->toBe(0)
        ->and($alert->fresh()->status)->toBe(AlertStatus::Resolved)
        ->and($this->server->fresh()->isMonitorOnly)->toBeTrue();
});

it('refuses to forget a job that is still reporting', function (): void {
    $job = serverJobWithSnapshots($this->server, 'mariadb');

    Livewire::actingAs($this->user)->test(ServerBackups::class, ['device' => $this->server])
        ->assertDontSee('data-forget-server-backup-job', false)
        ->call('forget', $job->id)
        ->assertStatus(422);

    expect(ServerBackupJob::query()->count())->toBe(1);
});

it('lists every backup across the fleet with red rows first', function (): void {
    serverJobWithSnapshots($this->server, 'forgejo');
    serverJobWithSnapshots($this->server, 'mariadb', attributes: ['last_exit_code' => 1]);
    $pc = Device::factory()->active()->withBackupCredentials(setHoursAgo: 72)->create(['hostname' => 'ACCOUNTS-PC']);
    $pc->forceFill(['last_good_backup_at' => now()->subHours(2), 'last_backup_at' => now()->subHours(2), 'last_backup_status' => BackupRunStatus::Succeeded])->save();
    DeviceBackupSnapshot::factory()->create(['device_id' => $pc->id, 'taken_at' => now()->subHours(2), 'bytes' => 5 * 1024 ** 3]);
    Device::factory()->active()->withBackupCredentials(setHoursAgo: 72)->create(['hostname' => 'RECEPTION-PC']);
    Device::factory()->create(['hostname' => 'PENDING-BOX'])->serverBackupJobs()->create(['job' => 'hidden', 'last_reported_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('backups.index'))
        ->assertSuccessful()
        ->assertSeeInOrder(['data-backup-status="failed"', 'mariadb', 'Last run exited with code 1', 'data-backup-status="stale"', 'RECEPTION-PC', 'ACCOUNTS-PC', 'User profiles', '5.0 GB', 'achcto', 'forgejo'], false)
        ->assertDontSee('PENDING-BOX')
        ->assertSee('href="'.route('devices.backups', $pc).'"', false);
});

it('narrows the fleet list to backups that need attention', function (): void {
    serverJobWithSnapshots($this->server, 'forgejo');
    serverJobWithSnapshots($this->server, 'mariadb', attributes: ['last_exit_code' => 1]);

    Livewire::actingAs($this->user)->test(BackupsIndex::class)
        ->assertSee('forgejo')
        ->set('filter', 'attention')
        ->assertSee('mariadb')
        ->assertDontSee('forgejo');
});

it('shows calm empty and all clear states on the fleet list', function (): void {
    Livewire::actingAs($this->user)->test(BackupsIndex::class)
        ->assertSee('No backups yet');

    serverJobWithSnapshots($this->server, 'forgejo');

    Livewire::actingAs($this->user)->test(BackupsIndex::class)
        ->set('filter', 'attention')
        ->assertSee('All clear');
});

it('refreshes the fleet list live and throttles routine device updates', function (): void {
    $component = Livewire::actingAs($this->user)->test(BackupsIndex::class)
        ->assertDontSee('cantina');

    serverJobWithSnapshots($this->server, 'cantina');

    $component->dispatch('echo-private:devices,DeviceUpdated', ['deviceId' => $this->server->id])
        ->assertDontSee('cantina')
        ->dispatch('echo-private:devices,ServerBackupsReported', ['deviceId' => $this->server->id])
        ->assertSee('cantina');
});

it('keeps a flat query count as the fleet grows', function (): void {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(BackupsIndex::class);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addBackups = function (): void {
        serverJobWithSnapshots(Device::factory()->active()->monitorOnly()->create(), 'mariadb', snapshots: 1);
        $pc = Device::factory()->active()->withBackupCredentials()->create();
        DeviceBackupSnapshot::factory()->create(['device_id' => $pc->id]);
    };

    $addBackups();
    $small = $queriesFor();

    collect(range(1, 6))->each(fn () => $addBackups());

    expect($queriesFor())->toBe($small);
});

it('keeps the Backups tab query count flat as a server gains snapshots', function (): void {
    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(ServerBackups::class, ['device' => $this->server]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $job = serverJobWithSnapshots($this->server, 'mariadb', snapshots: 2);
    $small = $queriesFor();

    ServerBackupSnapshot::factory()->count(30)->create(['server_backup_job_id' => $job->id]);

    expect($queriesFor())->toBe($small);
});

it('adds Backups to the fleet navigation and keeps it to users who may see devices', function (): void {
    $this->actingAs($this->user)->get(route('backups.index'))
        ->assertSeeInOrder(['Fleet', 'Devices', 'Hardware', 'Backups', 'Automation'])
        ->assertSeeHtml('href="'.route('backups.index').'" data-current');

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);
    $this->actingAs($this->user)->get(route('backups.index'))->assertForbidden();
});
