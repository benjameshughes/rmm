<?php

namespace Database\Factories;

use App\Enums\CommandStatus;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceCommand> */
class DeviceCommandFactory extends Factory
{
    protected $model = DeviceCommand::class;

    public function definition(): array
    {
        $scriptTypes = ['powershell', 'cmd', 'bash', 'sh'];
        $statuses = [
            CommandStatus::Pending,
            CommandStatus::Sent,
            CommandStatus::Running,
            CommandStatus::Completed,
            CommandStatus::Failed,
        ];

        $scripts = [
            'powershell' => ['Get-Process', 'Get-Service', 'Stop-Computer -Force', 'Restart-Computer -Force'],
            'cmd' => ['ipconfig /all', 'systeminfo', 'logoff'],
            'bash' => ['ps aux', 'systemctl status', 'df -h', 'free -m'],
        ];

        $scriptType = $this->faker->randomElement($scriptTypes);
        $scriptContent = $this->faker->randomElement($scripts[$scriptType] ?? ['echo "test"']);

        return [
            'device_id' => Device::factory(),
            'script_id' => null,
            'script_content' => $scriptContent,
            'script_type' => $scriptType,
            'status' => $this->faker->randomElement($statuses),
            'queued_at' => now(),
            'queued_by' => User::factory(),
            'timeout_seconds' => $this->faker->randomElement([60, 120, 300, 600]),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => CommandStatus::Pending,
            'sent_at' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CommandStatus::Completed,
            'sent_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(1),
            'output' => 'Command executed successfully',
            'exit_code' => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CommandStatus::Failed,
            'sent_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(1),
            'output' => '',
            'exit_code' => 1,
            'error_message' => 'Command failed to execute',
        ]);
    }
}
