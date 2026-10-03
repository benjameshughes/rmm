<?php

namespace Database\Factories;

use App\Enums\DevicePowerState;
use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    private array $windowsHostnames = [
        'DESKTOP-5ULJ14E', 'WORKSTATION-01', 'DEV-PC-ALPHA', 'SERVER-PROD-01',
        'LAPTOP-SALES-03', 'PC-ACCOUNTING', 'DESKTOP-MARKETING', 'WS-DEVELOPER',
    ];

    private array $linuxHostnames = [
        'web-server-01', 'db-primary', 'api-gateway', 'worker-node-1',
        'redis-cache', 'monitoring-01', 'backup-server', 'jenkins-ci',
    ];

    private array $cpuModels = [
        'Intel(R) Core(TM) i9-13900K',
        'Intel(R) Core(TM) i7-12700K',
        'Intel(R) Core(TM) i5-12400F',
        'AMD Ryzen 9 7950X',
        'AMD Ryzen 7 5800X',
        'AMD Ryzen 5 5600X',
        'Intel(R) Xeon(R) E-2388G',
        'Intel(R) Xeon(R) Gold 6348',
    ];

    public function definition(): array
    {
        $isWindows = $this->faker->boolean(60);

        return [
            'hostname' => $isWindows
                ? $this->faker->randomElement($this->windowsHostnames).'-'.Str::upper(Str::random(4))
                : $this->faker->randomElement($this->linuxHostnames),
            'hardware_fingerprint' => Str::uuid()->toString(),
            'api_key_hash' => null,
            'pending_api_key' => null,
            'api_key_issued_at' => null,
            'api_key_claimed_at' => null,
            'status' => DeviceStatus::Pending,
            'os' => $isWindows ? 'Windows 11 Pro' : 'Ubuntu 24.04 LTS',
            'os_name' => $isWindows ? 'Windows' : 'Ubuntu',
            'os_version' => $isWindows ? '10.0.22631' : '24.04',
            'cpu_model' => $this->faker->randomElement($this->cpuModels),
            'cpu_cores' => $this->faker->randomElement([4, 6, 8, 12, 16, 24, 32]),
            'total_ram_gb' => $this->faker->randomElement([8, 16, 32, 64, 128]),
            'disks' => $this->generateDisks($isWindows),
            'last_ip' => $this->faker->ipv4(),
            'last_seen' => null,
        ];
    }

    public function active(): static
    {
        return $this->withApiKey()->state(fn (): array => [
            'last_seen' => now()->subMinutes($this->faker->numberBetween(1, 10)),
        ]);
    }

    public function offline(): static
    {
        return $this->withApiKey()->state(fn (): array => [
            'last_seen' => now()->subHours($this->faker->numberBetween(1, 48)),
        ]);
    }

    /**
     * The agent announced it is sleeping or shutting down. last_seen is left as it was.
     */
    public function poweringOff(): static
    {
        return $this->state(fn (): array => [
            'power_state' => DevicePowerState::PoweringOff,
            'power_state_changed_at' => now(),
        ]);
    }

    /**
     * The agent announced it has woken or booted, which also counts as a check-in.
     */
    public function poweringOn(): static
    {
        return $this->state(fn (): array => [
            'last_seen' => now(),
            'power_state' => DevicePowerState::PoweringOn,
            'power_state_changed_at' => now(),
        ]);
    }

    /**
     * An approved device whose agent has already collected the given key.
     * A random key is generated per model when none is given.
     */
    public function withApiKey(?string $apiKey = null): static
    {
        return $this->state(fn (): array => [
            'status' => DeviceStatus::Active,
            'api_key_hash' => Device::hashApiKey($apiKey ?? Str::random(64)),
            'pending_api_key' => null,
            'api_key_issued_at' => now()->subMinute(),
            'api_key_claimed_at' => now(),
        ]);
    }

    /**
     * An approved device whose key has been issued but not yet collected by the agent.
     */
    public function awaitingKeyClaim(string $apiKey = 'TEST-KEY'): static
    {
        return $this->state(fn (): array => [
            'status' => DeviceStatus::Active,
            'api_key_hash' => Device::hashApiKey($apiKey),
            'pending_api_key' => $apiKey,
            'api_key_issued_at' => now(),
            'api_key_claimed_at' => null,
        ]);
    }

    /**
     * A device whose agent reported it is read-only, such as the Linux agent.
     */
    public function monitorOnly(): static
    {
        return $this->linux()->state(fn (): array => [
            'is_monitor_only' => true,
        ]);
    }

    public function windows(): static
    {
        return $this->state(fn (): array => [
            'hostname' => $this->faker->randomElement($this->windowsHostnames).'-'.Str::upper(Str::random(4)),
            'os' => 'Windows 11 Pro',
            'os_name' => 'Windows',
            'os_version' => '10.0.22631',
            'disks' => $this->generateDisks(true),
        ]);
    }

    public function linux(): static
    {
        return $this->state(fn (): array => [
            'hostname' => $this->faker->randomElement($this->linuxHostnames),
            'os' => 'Ubuntu 24.04 LTS',
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'disks' => $this->generateDisks(false),
        ]);
    }

    private function generateDisks(bool $isWindows): array
    {
        if ($isWindows) {
            $disks = [
                [
                    'name' => 'C:',
                    'mount_point' => 'C:\\',
                    'total_gb' => $this->faker->randomElement([256, 512, 1000, 2000]),
                    'available_gb' => 0,
                ],
            ];
            $disks[0]['available_gb'] = round($disks[0]['total_gb'] * $this->faker->randomFloat(2, 0.15, 0.7), 1);

            if ($this->faker->boolean(40)) {
                $dataSize = $this->faker->randomElement([500, 1000, 2000, 4000]);
                $disks[] = [
                    'name' => 'D:',
                    'mount_point' => 'D:\\',
                    'total_gb' => $dataSize,
                    'available_gb' => round($dataSize * $this->faker->randomFloat(2, 0.3, 0.8), 1),
                ];
            }

            return $disks;
        }

        $rootSize = $this->faker->randomElement([50, 100, 200, 500]);

        return [
            [
                'name' => 'root',
                'mount_point' => '/',
                'total_gb' => $rootSize,
                'available_gb' => round($rootSize * $this->faker->randomFloat(2, 0.2, 0.6), 1),
            ],
            [
                'name' => 'data',
                'mount_point' => '/var/lib/data',
                'total_gb' => $this->faker->randomElement([500, 1000, 2000]),
                'available_gb' => round($this->faker->randomFloat(2, 100, 800), 1),
            ],
        ];
    }
}
