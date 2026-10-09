<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\ServerBackupJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A healthy job that last ran half an hour ago. Times are built when each
 * model is made, so a long test run never leaves them stale.
 *
 * @extends Factory<ServerBackupJob>
 */
final class ServerBackupJobFactory extends Factory
{
    public function definition(): array
    {
        $bytes = $this->faker->numberBetween(500_000_000, 5_000_000_000);

        return [
            'device_id' => Device::factory()->active()->monitorOnly(),
            'job' => $this->faker->unique()->word(),
            'tool' => 'restic',
            'repository' => 'sftp:scarif:/mnt/scarif/data/backups/'.$this->faker->word(),
            'last_exit_code' => 0,
            'started_at' => fn (): mixed => now()->subMinutes(34),
            'finished_at' => fn (): mixed => now()->subMinutes(30),
            'status_file_modified_at' => fn (): mixed => now()->subMinutes(30),
            'snapshot_count' => 47,
            'total_size' => $bytes * 3,
            'total_uncompressed_size' => $bytes * 6,
            'compression_ratio' => 2.0,
            'compression_space_saving' => 50.0,
            'total_blob_count' => 12000,
            'latest_snapshot_at' => fn (): mixed => now()->subMinutes(30),
            'latest_bytes_processed' => $bytes,
            'baseline_bytes_processed' => $bytes,
            'last_error' => null,
            'last_reported_at' => fn (): mixed => now(),
        ];
    }

    public function failed(int $exitCode = 1): static
    {
        return $this->state(fn (): array => ['last_exit_code' => $exitCode]);
    }

    public function overdue(int $hours = 5): static
    {
        return $this->state(fn (): array => [
            'latest_snapshot_at' => now()->subHours($hours),
            'finished_at' => now()->subHours($hours),
        ]);
    }

    public function shrunk(int $latestBytes = 0): static
    {
        return $this->state(fn (): array => [
            'latest_bytes_processed' => $latestBytes,
            'baseline_bytes_processed' => 1_200_000_000,
        ]);
    }

    public function unreadable(): static
    {
        return $this->state(fn (): array => ['last_error' => 'expected value at line 1 column 1']);
    }

    public function missing(): static
    {
        return $this->state(fn (): array => ['last_reported_at' => now()->subHours(config('backup.servers.forget_missing_after_hours') + 1)]);
    }
}
