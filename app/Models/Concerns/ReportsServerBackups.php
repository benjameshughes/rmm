<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ServerBackupJob;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Linux servers back themselves up with their own scripts, and the agent
 * reports each script's status file as a job. The RMM only watches them.
 */
trait ReportsServerBackups
{
    public function serverBackupJobs(): HasMany
    {
        return $this->hasMany(ServerBackupJob::class);
    }
}
