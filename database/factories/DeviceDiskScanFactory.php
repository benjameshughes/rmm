<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceDiskScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceDiskScan>
 */
final class DeviceDiskScanFactory extends Factory
{
    public function definition(): array
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/disk-usage-c-drive.json')), true);

        return [
            'device_id' => Device::factory()->active()->windows(),
            'root' => $data['root'],
            'depth' => 4,
            'scanned_at' => now(),
            'duration_ms' => $data['duration_ms'],
            'allocated' => $data['totals']['allocated'],
            'files' => $data['totals']['files'],
            'error_count' => $data['errors']['count'],
            'data' => $data,
        ];
    }
}
