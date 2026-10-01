<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Index;
use App\Livewire\Devices\Show;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    Cache::forever(config('agent.latest_version_cache_key'), '0.5.1');
    $this->user = User::factory()->create();
});

it('shows the update badge and banner for outdated agents', function (): void {
    Device::factory()->active()->create(['hostname' => 'OLD-PC', 'agent_version' => '0.5.0']);
    Device::factory()->active()->create(['hostname' => 'NEW-PC', 'agent_version' => '0.5.1']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('1 device running an old agent (latest 0.5.1)')
        ->assertSee('Update available 0.5.0')
        ->assertSee('Agent 0.5.1')
        ->assertSee('Update all');
});

it('shows no badge or banner when every agent is current', function (): void {
    Device::factory()->active()->create(['agent_version' => '0.5.1']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertDontSee('running an old agent')
        ->assertDontSee('Update available');
});

it('shows no badge or banner before the latest version is known', function (): void {
    Cache::forget(config('agent.latest_version_cache_key'));
    Device::factory()->active()->create(['agent_version' => '0.1.0']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertDontSee('running an old agent')
        ->assertDontSee('Update available');
});

it('queues the update-agent script on outdated active devices only', function (): void {
    $outdated = Device::factory()->active()->create(['agent_version' => '0.5.0']);
    Device::factory()->active()->create(['agent_version' => '0.5.1']);
    Device::factory()->create(['agent_version' => '0.4.0']);
    Device::factory()->active()->create(['agent_version' => null]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->call('updateOutdatedAgents')
        ->assertDispatched('command-queued');

    $command = DeviceCommand::query()->sole();
    expect($command->device_id)->toBe($outdated->id)
        ->and($command->script_id)->toBe(Script::findSystem('update-agent')->id)
        ->and($command->queued_by)->toBe($this->user->id);
});

it('refreshes badges and banner when a new release is announced', function (): void {
    Device::factory()->active()->create(['agent_version' => '0.5.1']);

    $component = Livewire::actingAs($this->user)->test(Index::class)
        ->assertDontSee('running an old agent');

    Cache::forever(config('agent.latest_version_cache_key'), '0.6.0');

    $component->dispatch('echo-private:devices,LatestAgentVersionChanged', ['version' => '0.6.0'])
        ->assertSee('1 device running an old agent (latest 0.6.0)')
        ->assertSee('Update available 0.5.1');
});

it('shows the update badge and agent version on the device page', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.0']);

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertSee('Update available 0.5.0')
        ->assertSee('Agent Version')
        ->assertSee('Update Agent');
});

it('hides the device page badge when the agent is current', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.1']);

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertDontSee('Update available');
});

it('refreshes the device page badge when a new release is announced', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.1']);

    $component = Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->assertDontSee('Update available');

    Cache::forever(config('agent.latest_version_cache_key'), '0.6.0');

    $component->dispatch('echo-private:devices,LatestAgentVersionChanged', ['version' => '0.6.0'])
        ->assertSee('Update available 0.5.1');
});

it('queues the update-agent script from the device page', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.0']);

    Livewire::actingAs($this->user)->test(Show::class, ['device' => $device])
        ->call('updateAgent')
        ->assertDispatched('command-queued');

    $command = DeviceCommand::query()->sole();
    expect($command->device_id)->toBe($device->id)
        ->and($command->script_id)->toBe(Script::findSystem('update-agent')->id)
        ->and($command->timeout_seconds)->toBe(300);
});

it('ships the update-agent system script', function (): void {
    $script = Script::findSystem('update-agent');

    expect($script->script_content)->toContain('BenJHRMM')
        ->toContain('& $exe update')
        ->toContain('exit $LASTEXITCODE')
        ->and($script->requires_admin)->toBeTrue()
        ->and($script->category->value)->toBe('updates');
});
