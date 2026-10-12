<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\ZebraTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZebraTemplate>
 */
final class ZebraTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->active()->windows(),
            'source_hostname' => 'LABEL-PC-1',
            'printer_name' => 'ZDesigner GK420d',
            'driver_name' => 'ZDesigner GK420d',
            'settings' => base64_encode(gzencode($this->faker->sha256())),
            'captured_at' => now(),
        ];
    }
}
