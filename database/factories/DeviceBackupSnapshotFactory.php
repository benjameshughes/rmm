<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceBackupSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceBackupSnapshot>
 */
final class DeviceBackupSnapshotFactory extends Factory
{
    public function definition(): array
    {
        $snapshotId = $this->faker->sha256();

        return [
            'device_id' => Device::factory()->active()->withBackupCredentials(),
            'snapshot_id' => $snapshotId,
            'short_id' => substr($snapshotId, 0, 8),
            'taken_at' => now()->subHours($this->faker->numberBetween(1, 200)),
            'paths' => ['C:\\Users\\anna', 'C:\\Users\\ben'],
            'files' => $this->faker->numberBetween(1000, 90000),
            'bytes' => $this->faker->numberBetween(1_000_000_000, 90_000_000_000),
            'bytes_added' => $this->faker->numberBetween(0, 2_000_000_000),
        ];
    }
}
