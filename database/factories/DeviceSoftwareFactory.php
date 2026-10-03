<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceSoftware;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSoftware>
 */
final class DeviceSoftwareFactory extends Factory
{
    public function definition(): array
    {
        $app = $this->faker->randomElement([
            ['Mozilla.Firefox', 'Mozilla Firefox'],
            ['Google.Chrome', 'Google Chrome'],
            ['7zip.7zip', '7-Zip'],
            ['Notepad++.Notepad++', 'Notepad++'],
            ['VideoLAN.VLC', 'VLC media player'],
        ]);

        return [
            'device_id' => Device::factory()->active()->windows(),
            'package_id' => $app[0],
            'name' => $app[1],
            'installed_version' => $this->faker->numerify('1##.0'),
            'latest_version' => null,
            'is_update_available' => false,
            'source' => 'winget',
            'last_seen_at' => now(),
        ];
    }

    public function outdated(string $latestVersion = '999.0'): static
    {
        return $this->state(fn (): array => [
            'latest_version' => $latestVersion,
            'is_update_available' => true,
        ]);
    }

    public function fromArp(): static
    {
        return $this->state(fn (): array => [
            'package_id' => 'ARP\\Machine\\X64\\{'.$this->faker->uuid().'}',
            'source' => null,
        ]);
    }
}
