<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PathKind;
use App\Models\Device;
use App\Models\DeviceQuarantine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceQuarantine>
 */
final class DeviceQuarantineFactory extends Factory
{
    public function definition(): array
    {
        $quarantinedAt = now()->subDay();
        $folder = $quarantinedAt->utc()->format('Ymd\THis\Z').'-'.fake()->regexify('[0-9a-f]{8}');

        return [
            'device_id' => Device::factory()->active()->windows(),
            'device_command_id' => null,
            'path' => 'C:\\Veeam Backup Cache',
            'kind' => PathKind::Folder,
            'folder' => $folder,
            'quarantined_to' => "C:\\ProgramData\\RMM\\Quarantine\\{$folder}\\Veeam Backup Cache",
            'bytes' => 111_400_000_000,
            'files' => 1200,
            'quarantined_at' => $quarantinedAt,
            'purge_after' => $quarantinedAt->copy()->addDays(config('devices.delete_path.quarantine_days')),
        ];
    }

    public function due(): self
    {
        return $this->state(fn (): array => [
            'quarantined_at' => now()->subDays(8),
            'purge_after' => now()->subDay(),
        ]);
    }

    public function purged(): self
    {
        return $this->state(fn (): array => ['purged_at' => now()]);
    }

    public function restored(): self
    {
        return $this->state(fn (): array => ['restored_at' => now()]);
    }
}
