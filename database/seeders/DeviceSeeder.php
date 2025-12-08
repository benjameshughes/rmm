<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    public function run(): void
    {
        // Create a healthy Windows workstation (online)
        $healthyWindows = Device::factory()->active()->windows()->create([
            'hostname' => 'WORKSTATION-DEV-01',
            'cpu_model' => 'Intel(R) Core(TM) i9-13900K',
            'cpu_cores' => 24,
            'total_ram_gb' => 64,
        ]);
        $this->createMetricsHistory($healthyWindows, 'healthy');

        // Create a stressed Linux server (online, high load)
        $stressedLinux = Device::factory()->active()->linux()->create([
            'hostname' => 'web-server-prod-01',
            'cpu_model' => 'Intel(R) Xeon(R) Gold 6348',
            'cpu_cores' => 32,
            'total_ram_gb' => 128,
        ]);
        $this->createMetricsHistory($stressedLinux, 'stressed');

        // Create an offline Windows machine
        $offlineWindows = Device::factory()->offline()->windows()->create([
            'hostname' => 'LAPTOP-SALES-05',
            'cpu_model' => 'Intel(R) Core(TM) i7-12700K',
            'cpu_cores' => 12,
            'total_ram_gb' => 32,
        ]);
        $this->createMetricsHistory($offlineWindows, 'mixed', 24);

        // Create a pending device (no metrics)
        Device::factory()->windows()->create([
            'hostname' => 'NEW-PC-PENDING',
            'status' => Device::STATUS_PENDING,
        ]);

        // Create a few more random active devices
        Device::factory()
            ->count(3)
            ->active()
            ->create()
            ->each(fn (Device $device) => $this->createMetricsHistory($device, 'mixed'));
    }

    private function createMetricsHistory(Device $device, string $profile, int $hoursBack = 12): void
    {
        $totalRamMib = ($device->total_ram_gb ?? 16) * 1024;

        // Create metrics for the past X hours (one every 30 minutes)
        for ($i = $hoursBack * 2; $i >= 0; $i--) {
            $minutesAgo = $i * 30;

            $factory = DeviceMetric::factory()->state([
                'device_id' => $device->id,
                'memory_total_mib' => $totalRamMib,
                'recorded_at' => now()->subMinutes($minutesAgo),
            ]);

            match ($profile) {
                'healthy' => $factory->healthy()->create(),
                'stressed' => $factory->stressed()->create(),
                default => $factory->create(),
            };
        }
    }
}
