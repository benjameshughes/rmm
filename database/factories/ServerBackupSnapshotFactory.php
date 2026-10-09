<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ServerBackupJob;
use App\Models\ServerBackupSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerBackupSnapshot>
 */
final class ServerBackupSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $snapshotId = $this->faker->sha256();

        return [
            'server_backup_job_id' => ServerBackupJob::factory(),
            'snapshot_id' => $snapshotId,
            'short_id' => substr($snapshotId, 0, 8),
            'taken_at' => fn (): mixed => now()->subHours($this->faker->numberBetween(1, 200)),
            'hostname' => 'achcto',
            'paths' => ['/all-databases.sql'],
            'tags' => ['hourly'],
            'duration_seconds' => $this->faker->randomFloat(1, 5, 300),
            'files_new' => 1,
            'files_changed' => 0,
            'files_unmodified' => 0,
            'total_files_processed' => 1,
            'total_bytes_processed' => $this->faker->numberBetween(500_000_000, 5_000_000_000),
            'data_added' => $this->faker->numberBetween(0, 50_000_000),
            'data_added_packed' => $this->faker->numberBetween(0, 20_000_000),
        ];
    }
}
