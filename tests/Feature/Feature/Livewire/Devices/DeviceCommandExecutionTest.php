<?php

declare(strict_types=1);

use App\Livewire\Devices\Index;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

describe('Device Index Commands', function (): void {
    it('can queue power off command from index', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('powerOff', $device->id)
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Stop-Computer -Force');
        expect($command->script_type)->toBe('powershell');
        expect($command->status)->toBe(DeviceCommand::STATUS_PENDING);
        expect($command->queued_by)->toBe($user->id);
        expect($command->timeout_seconds)->toBe(300);
    });

    it('can queue restart command from index', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('restart', $device->id)
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Restart-Computer -Force');
        expect($command->script_type)->toBe('powershell');
        expect($command->status)->toBe(DeviceCommand::STATUS_PENDING);
    });

    it('can queue check for updates command from index', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('checkForUpdates', $device->id)
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Get-WindowsUpdate -Install -AcceptAll -AutoReboot');
        expect($command->script_type)->toBe('powershell');
        expect($command->status)->toBe(DeviceCommand::STATUS_PENDING);
    });

    it('requires authentication to queue commands from index', function (): void {
        $device = Device::factory()->active()->create();

        Livewire::test(Index::class)
            ->call('powerOff', $device->id)
            ->assertUnauthorized();

        expect(DeviceCommand::count())->toBe(0);
    });
});

describe('Device Show Commands', function (): void {
    it('can queue power off command from show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->call('powerOff')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Stop-Computer -Force');
        expect($command->script_type)->toBe('powershell');
        expect($command->status)->toBe(DeviceCommand::STATUS_PENDING);
        expect($command->queued_by)->toBe($user->id);
    });

    it('can queue restart command from show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->call('restart')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Restart-Computer -Force');
        expect($command->script_type)->toBe('powershell');
    });

    it('can queue log off command from show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->call('logOff')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('logoff');
        expect($command->script_type)->toBe('cmd');
        expect($command->status)->toBe(DeviceCommand::STATUS_PENDING);
    });

    it('can queue check for updates command from show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->call('checkForUpdates')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_content)->toBe('Get-WindowsUpdate -Install -AcceptAll -AutoReboot');
        expect($command->script_type)->toBe('powershell');
    });

    it('displays recent commands on show page', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::factory()->count(5)->create([
            'device_id' => $device->id,
            'queued_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->assertViewHas('recentCommands', function ($commands) {
                return $commands->count() === 5;
            });
    });

    it('displays command status badges correctly', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        $pendingCommand = DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'Test',
            'script_type' => 'powershell',
            'status' => DeviceCommand::STATUS_PENDING,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device]);

        $component->assertSee('Pending');
    });

    it('limits recent commands to 10', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::factory()->count(15)->create([
            'device_id' => $device->id,
            'queued_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->assertViewHas('recentCommands', function ($commands) {
                return $commands->count() === 10;
            });
    });

    it('requires authentication to queue commands from show page', function (): void {
        $device = Device::factory()->active()->create();

        Livewire::test(Show::class, ['device' => $device])
            ->call('powerOff')
            ->assertUnauthorized();

        expect(DeviceCommand::count())->toBe(0);
    });

    it('shows most recent commands first', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        $older = DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'Old Command',
            'script_type' => 'powershell',
            'status' => DeviceCommand::STATUS_PENDING,
            'queued_at' => now()->subHours(2),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        $newer = DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'New Command',
            'script_type' => 'powershell',
            'status' => DeviceCommand::STATUS_PENDING,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['device' => $device])
            ->assertViewHas('recentCommands', function ($commands) use ($newer, $older) {
                return $commands->first()->id === $newer->id
                    && $commands->last()->id === $older->id;
            });
    });
});
