<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BackupRunStatus;
use App\Models\Device;
use App\Models\DeviceBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceBackup>
 */
final class DeviceBackupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->active()->withBackupCredentials(),
            'device_command_id' => null,
            'status' => BackupRunStatus::Succeeded,
            'exit_code' => 0,
            'snapshot_id' => $this->faker->sha256(),
            'files_new' => $this->faker->numberBetween(0, 500),
            'files_changed' => $this->faker->numberBetween(0, 200),
            'files_unmodified' => $this->faker->numberBetween(1000, 90000),
            'data_added' => $this->faker->numberBetween(0, 2_000_000_000),
            'total_bytes_processed' => $this->faker->numberBetween(1_000_000_000, 90_000_000_000),
            'duration_seconds' => $this->faker->randomFloat(1, 30, 3600),
            'errors' => [],
            'finished_at' => now(),
        ];
    }

    public function failed(int $exitCode = 1): static
    {
        return $this->state(fn (): array => [
            'status' => BackupRunStatus::Failed,
            'exit_code' => $exitCode,
            'snapshot_id' => null,
            'files_new' => null,
            'files_changed' => null,
            'files_unmodified' => null,
            'data_added' => null,
            'total_bytes_processed' => null,
            'errors' => ['Fatal: unable to open repository'],
        ]);
    }
}
