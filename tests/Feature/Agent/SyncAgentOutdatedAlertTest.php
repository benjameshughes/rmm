<?php

declare(strict_types=1);

use App\Actions\Agent\SyncAgentOutdatedAlert;
use App\Enums\AlertMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\DeviceStatus;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

function syncOutdatedAlert(Device $device, ?string $latestVersion): void
{
    app(SyncAgentOutdatedAlert::class)($device->fresh(), $latestVersion);
}

it('raises one outdated-agent alert from the built-in rule', function (): void {
    $device = Device::factory()->active()->create(['hostname' => 'DESKTOP-5ULJ14E', 'agent_version' => '0.5.0']);

    syncOutdatedAlert($device, '0.5.1');
    syncOutdatedAlert($device, '0.5.1');

    $alert = Alert::query()->sole();
    expect($alert->metric)->toBe(AlertMetric::AgentOutdated)
        ->and($alert->status)->toBe(AlertStatus::Triggered)
        ->and($alert->severity)->toBe(AlertSeverity::Warning)
        ->and($alert->device_id)->toBe($device->id)
        ->and($alert->message)->toBe('DESKTOP-5ULJ14E is on agent 0.5.0, latest is 0.5.1')
        ->and($alert->alertRule->is(AlertRule::agentOutdated()))->toBeTrue();
    expect(AlertRule::query()->where('metric', AlertMetric::AgentOutdated)->count())->toBe(1);
});

it('resolves the alert once the agent catches up', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.0']);
    syncOutdatedAlert($device, '0.5.1');

    $device->update(['agent_version' => '0.5.1']);
    syncOutdatedAlert($device, '0.5.1');

    expect(Alert::query()->sole()->status)->toBe(AlertStatus::Resolved);
});

it('keeps the open alert message current when a newer release lands', function (): void {
    $device = Device::factory()->active()->create(['hostname' => 'PC-1', 'agent_version' => '0.5.0']);
    syncOutdatedAlert($device, '0.5.1');

    syncOutdatedAlert($device, '0.6.0');

    expect(Alert::query()->sole()->message)->toBe('PC-1 is on agent 0.5.0, latest is 0.6.0');
});

it('raises nothing while the built-in rule is switched off', function (): void {
    AlertRule::agentOutdated()->update(['is_active' => false]);
    $device = Device::factory()->active()->create(['agent_version' => '0.5.0']);

    syncOutdatedAlert($device, '0.5.1');

    expect(Alert::query()->count())->toBe(0);
});

it('ignores devices that are not active or have not reported a version', function (array $attributes): void {
    $device = Device::factory()->create($attributes);

    syncOutdatedAlert($device, '0.5.1');

    expect(Alert::query()->count())->toBe(0);
})->with([
    'pending' => [['status' => DeviceStatus::Pending, 'agent_version' => '0.5.0']],
    'revoked' => [['status' => DeviceStatus::Revoked, 'agent_version' => '0.5.0']],
    'no version reported' => [['status' => DeviceStatus::Active, 'agent_version' => null]],
]);

it('does nothing while the latest version is unknown', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.0']);

    syncOutdatedAlert($device, null);

    expect(Alert::query()->count())->toBe(0)
        ->and(AlertRule::query()->count())->toBe(0);
});

it('leaves an up to date device alone', function (): void {
    $device = Device::factory()->active()->create(['agent_version' => '0.5.1']);

    syncOutdatedAlert($device, '0.5.1');

    expect(Alert::query()->count())->toBe(0);
});
