<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Enums\CommandStatus;
use App\Livewire\Devices\Commands;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Livewire\Devices\Overview;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => app(SyncSystemScripts::class)());

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
        expect($command->script_id)->toBe(Script::findSystem('shutdown')->id);
        expect($command->status)->toBe(CommandStatus::Pending);
        expect($command->queued_by)->toBe($user->id);
        expect($command->timeout_seconds)->toBe(config('scripts.system.shutdown.timeout_seconds'));
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
        expect($command->script_id)->toBe(Script::findSystem('restart')->id);
        expect($command->status)->toBe(CommandStatus::Pending);
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
        expect($command->script_id)->toBe(Script::findSystem('windows-update')->id);
        expect($command->status)->toBe(CommandStatus::Pending);
    });

    it('requires authentication to queue commands from index', function (): void {
        $device = Device::factory()->active()->create();

        Livewire::test(Index::class)
            ->assertForbidden();

        expect(DeviceCommand::count())->toBe(0);
    });
});

describe('Device page commands', function (): void {
    it('can queue power off command from the device header', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Header::class, ['device' => $device])
            ->call('powerOff')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_id)->toBe(Script::findSystem('shutdown')->id);
        expect($command->status)->toBe(CommandStatus::Pending);
        expect($command->queued_by)->toBe($user->id);
    });

    it('can queue restart command from the device header', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Header::class, ['device' => $device])
            ->call('restart')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_id)->toBe(Script::findSystem('restart')->id);
    });

    it('can queue log off command from the device header', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Header::class, ['device' => $device])
            ->call('logOff')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_id)->toBe(Script::findSystem('log-off')->id);
        expect($command->status)->toBe(CommandStatus::Pending);
    });

    it('can queue check for updates command from the device header', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        Livewire::actingAs($user)
            ->test(Header::class, ['device' => $device])
            ->call('checkForUpdates')
            ->assertDispatched('command-queued');

        $command = DeviceCommand::where('device_id', $device->id)->first();

        expect($command)->not->toBeNull();
        expect($command->script_id)->toBe(Script::findSystem('windows-update')->id);
    });

    it('displays recent commands on the commands tab', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::factory()->count(5)->create([
            'device_id' => $device->id,
            'queued_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Commands::class, ['device' => $device])
            ->assertViewHas('commands', function ($commands) {
                return $commands->count() === 5;
            });
    });

    it('displays command status badges correctly', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'Test',
            'script_type' => 'powershell',
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        Livewire::actingAs($user)
            ->test(Commands::class, ['device' => $device])
            ->assertSee('Pending');
    });

    it('limits the overview to the configured number of recent commands', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::factory()->count(15)->create([
            'device_id' => $device->id,
            'queued_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Overview::class, ['device' => $device])
            ->assertViewHas('recentCommands', fn ($commands): bool => $commands->count() === config('devices.metrics.recent_commands'));
    });

    it('pages the full history on the commands tab', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        DeviceCommand::factory()->count(config('commands.per_page') + 3)->create([
            'device_id' => $device->id,
            'queued_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Commands::class, ['device' => $device])
            ->assertViewHas('commands', fn ($commands): bool => $commands->count() === config('commands.per_page') && $commands->total() === config('commands.per_page') + 3);
    });

    it('requires authentication to queue commands from the device header', function (): void {
        $device = Device::factory()->active()->create();

        Livewire::test(Header::class, ['device' => $device])
            ->assertForbidden();

        expect(DeviceCommand::count())->toBe(0);
    });

    it('shows most recent commands first', function (): void {
        $user = User::factory()->create();
        $device = Device::factory()->active()->create();

        $older = DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'Old Command',
            'script_type' => 'powershell',
            'status' => CommandStatus::Pending,
            'queued_at' => now()->subHours(2),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        $newer = DeviceCommand::create([
            'device_id' => $device->id,
            'script_content' => 'New Command',
            'script_type' => 'powershell',
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
            'queued_by' => $user->id,
            'timeout_seconds' => 300,
        ]);

        Livewire::actingAs($user)
            ->test(Commands::class, ['device' => $device])
            ->assertViewHas('commands', function ($commands) use ($newer, $older) {
                return $commands->first()->id === $newer->id
                    && $commands->last()->id === $older->id;
            });
    });
});
