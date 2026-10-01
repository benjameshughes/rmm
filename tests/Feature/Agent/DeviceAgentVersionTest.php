<?php

declare(strict_types=1);

use App\Enums\AlertStatus;
use App\Events\MetricsReceived;
use App\Listeners\SyncAgentOutdatedAlertForMetrics;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

pest()->use(RefreshDatabase::class);

it('knows when its agent is behind the latest release', function (?string $agentVersion, ?string $latestVersion, bool $isOutdated): void {
    $device = new Device(['agent_version' => $agentVersion]);

    expect($device->isAgentOutdated($latestVersion))->toBe($isOutdated);
})->with([
    'unknown agent version' => [null, '0.5.1', false],
    'unknown latest version' => ['0.5.0', null, false],
    'both unknown' => [null, null, false],
    'equal' => ['0.5.1', '0.5.1', false],
    'older patch' => ['0.5.0', '0.5.1', true],
    'older minor across a digit boundary' => ['0.9.0', '0.10.0', true],
    'newer than the release' => ['0.6.0', '0.5.1', false],
]);

it('records the agent version from both metrics formats', function (array $input): void {
    $device = Device::factory()->withApiKey('KEY-VERSION')->create(['agent_version' => null]);

    $this->postJson('/api/metrics', [...$input, 'agent_version' => '0.5.1'], ['X-Agent-Key' => 'KEY-VERSION'])->assertSuccessful();

    expect($device->fresh()->agent_version)->toBe('0.5.1');
})->with([
    'standard' => [['cpu' => ['usage_percent' => 12], 'ram' => ['usage_percent' => 34]]],
    'raw netdata' => [['netdata_cpu' => []]],
]);

it('keeps the last known version when a post omits it', function (): void {
    $device = Device::factory()->withApiKey('KEY-VERSION')->create(['agent_version' => '0.5.0']);

    $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 12]], ['X-Agent-Key' => 'KEY-VERSION'])->assertSuccessful();

    expect($device->fresh()->agent_version)->toBe('0.5.0');
});

it('syncs the outdated-agent alert when metrics arrive', function (): void {
    Event::fake();

    Event::assertListening(MetricsReceived::class, SyncAgentOutdatedAlertForMetrics::class);
});

it('raises the alert on an old agent and resolves it once the device reports the update', function (): void {
    Cache::forever(config('agent.latest_version_cache_key'), '0.5.1');
    $device = Device::factory()->withApiKey('KEY-VERSION')->create(['agent_version' => null]);

    $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5], 'agent_version' => '0.5.0'], ['X-Agent-Key' => 'KEY-VERSION'])->assertSuccessful();

    expect(Alert::query()->where('device_id', $device->id)->sole()->status)->toBe(AlertStatus::Triggered);

    $this->postJson('/api/metrics', ['cpu' => ['usage_percent' => 5], 'agent_version' => '0.5.1'], ['X-Agent-Key' => 'KEY-VERSION'])->assertSuccessful();

    expect(Alert::query()->where('device_id', $device->id)->sole()->status)->toBe(AlertStatus::Resolved);
});
