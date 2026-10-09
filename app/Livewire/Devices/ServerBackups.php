<?php

declare(strict_types=1);

namespace App\Livewire\Devices;

use App\Actions\ServerBackup\SyncServerBackupAlerts;
use App\DTOs\Backups\ServerBackupJobCard;
use App\Enums\ServerBackupHealth;
use App\Models\Device;
use App\Models\ServerBackupJob;
use App\Queries\ServerBackupQueries;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A Linux server's own backup jobs, read from the status files its agent
 * reports: health, repository stats, charts of each job's snapshots and their
 * history. Read only: the RMM never runs anything on a server.
 */
final class ServerBackups extends Component
{
    use WithPagination;

    public Device $device;

    public function mount(Device $device): void
    {
        $this->authorize('view', $device);

        $this->device = $device;
    }

    /**
     * Only sent when a status file changed, so this renders about once an hour per job.
     */
    #[On('echo-private:devices.{device.id},ServerBackupsReported')]
    public function refreshJobs(): void {}

    /**
     * Drops a job whose status file stopped being reported, with its
     * snapshots, and resolves its alert.
     */
    public function forget(int $jobId, SyncServerBackupAlerts $syncAlerts): void
    {
        $this->authorize('forgetServerBackupJob', $this->device);

        $job = $this->device->serverBackupJobs()->findOrFail($jobId);

        abort_unless($job->health() === ServerBackupHealth::Missing, 422, 'Only a job whose status file is missing can be forgotten.');

        $job->delete();
        $syncAlerts($this->device);

        Flux::toast(text: "The {$job->job} job and its snapshots are gone from the RMM. Nothing on {$this->device->hostname} changed.", heading: 'Backup job forgotten', variant: 'success');
    }

    public function render(ServerBackupQueries $queries): View
    {
        $jobs = $this->device->serverBackupJobs()->orderBy('job')->get();
        $charts = $queries->charts($jobs);

        $cards = $jobs
            ->map(fn (ServerBackupJob $job): ServerBackupJobCard => new ServerBackupJobCard(
                job: $job,
                health: $job->health(),
                problem: $job->problem(),
                sizeChart: $charts[$job->id]['size'],
                addedChart: $charts[$job->id]['added'],
                snapshots: $job->snapshots()->latest('taken_at')->paginate(config('backup.servers.snapshots_per_page'), pageName: "snapshots-{$job->id}"),
            ))
            ->sortBy(fn (ServerBackupJobCard $card): int => $card->health->rank())
            ->values();

        return view('livewire.devices.server-backups', [
            'cards' => $cards,
            'attentionCount' => $cards->filter(fn (ServerBackupJobCard $card): bool => $card->health->needsAttention())->count(),
        ]);
    }
}
