<?php

declare(strict_types=1);

namespace Database\Factories;

use App\DTOs\Inventory\SystemInventory;
use App\Models\Device;
use App\Models\DeviceInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceInventory>
 */
final class DeviceInventoryFactory extends Factory
{
    public function definition(): array
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/system-inventory-dell-latitude.json')), true);

        return [
            'device_id' => Device::factory()->active()->windows(),
            'collected_at' => now(),
            'data' => $data,
            ...(new SystemInventory($data))->columns(),
        ];
    }
}
