<?php

declare(strict_types=1);

namespace App\DTOs\Backups;

use App\DTOs\MetricChart;
use App\Enums\ServerBackupHealth;
use App\Models\ServerBackupJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Number;

/**
 * Everything one job's card on a server's Backups tab shows, worked out once.
 */
final readonly class ServerBackupJobCard
{
    /**
     * @param  LengthAwarePaginator<int, \App\Models\ServerBackupSnapshot>  $snapshots
     */
    public function __construct(
        public ServerBackupJob $job,
        public ServerBackupHealth $health,
        public ?string $problem,
        public MetricChart $sizeChart,
        public MetricChart $addedChart,
        public LengthAwarePaginator $snapshots,
    ) {}

    /**
     * Says so when restic holds more snapshots than the RMM has been sent.
     */
    public function snapshotsDescription(): string
    {
        $stored = $this->snapshots->total();
        $inRepository = $this->job->snapshot_count;

        return $inRepository === null || $inRepository <= $stored
            ? 'Newest first.'
            : 'Newest first. The RMM has '.Number::format($stored).' of the '.Number::format($inRepository).': the agent only sends the newest '.config('backup.servers.max_snapshots').' each run.';
    }
}
