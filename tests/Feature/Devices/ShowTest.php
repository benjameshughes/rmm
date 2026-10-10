<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Livewire\Devices\Details;
use App\Livewire\Devices\Overview;
use App\Livewire\Devices\Pending;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

it('shows the device and its latest metrics on the overview', function (): void {
    $user = User::factory()->create();

    $device = Device::factory()->active()->create([
        'hostname' => 'WAREHOUSE-PC-07',
        'last_seen' => now(),
        'os' => 'Windows 11 Pro',
    ]);

    DeviceMetric::factory()->count(3)->create([
        'device_id' => $device->id,
        'cpu' => 22.22,
        'ram' => 44.44,
        'recorded_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('devices.show', $device))
        ->assertSuccessful()
        ->assertSee('WAREHOUSE-PC-07')
        ->assertSee('22.2%')
        ->assertSee('44.4%');
});

it('shows key status instead of any part of the key', function (): void {
    $user = User::factory()->create();

    $claimed = Device::factory()->withApiKey('KEY-SHOW-CLAIMED')->create();
    $awaiting = Device::factory()->awaitingKeyClaim('KEY-SHOW-AWAITING')->create();
    $pending = Device::factory()->create();

    $this->actingAs($user)
        ->get(route('devices.details', $claimed))
        ->assertSuccessful()
        ->assertSee('Claimed')
        ->assertDontSee('KEY-SHOW')
        ->assertDontSee(substr(Device::hashApiKey('KEY-SHOW-CLAIMED'), 0, 8));

    $this->actingAs($user)
        ->get(route('devices.details', $awaiting))
        ->assertSuccessful()
        ->assertSee('Awaiting agent')
        ->assertDontSee('KEY-SHOW');

    $this->actingAs($user)
        ->get(route('devices.details', $pending))
        ->assertSuccessful()
        ->assertSee('None')
        ->assertDontSee('Reset enrolment');
});

it('resets enrolment so the old key stops working', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('KEY-TO-RESET')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-TO-RESET'])->assertSuccessful();

    Livewire::actingAs($user)
        ->test(Details::class, ['device' => $device])
        ->assertSee('Reset enrolment')
        ->call('resetEnrolment')
        ->assertDispatched('notify');

    $device->refresh();
    expect($device->status)->toBe(DeviceStatus::Pending)
        ->and($device->api_key_hash)->toBeNull()
        ->and($device->pending_api_key)->toBeNull()
        ->and($device->api_key_issued_at)->toBeNull()
        ->and($device->api_key_claimed_at)->toBeNull();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-TO-RESET'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid or revoked device key.']);
});

it('lets a reset device re-enrol and collect a fresh key after re-approval', function (): void {
    $user = User::factory()->create();
    $device = Device::factory()->withApiKey('KEY-BEFORE-RESET')->create([
        'hostname' => 'REENROL-PC',
        'hardware_fingerprint' => 'FP-REENROL',
    ]);

    Livewire::actingAs($user)->test(Details::class, ['device' => $device])->call('resetEnrolment');

    $payload = ['hostname' => 'REENROL-PC', 'hardware_fingerprint' => 'FP-REENROL'];
    $this->postJson('/api/enroll', $payload)->assertSuccessful()->assertJsonPath('status', 'pending');
    $this->postJson('/api/check', $payload)->assertJsonMissingPath('api_key');

    Livewire::actingAs($user)->test(Pending::class)->call('approve', $device->id);

    $newKey = $this->postJson('/api/check', $payload)->assertSuccessful()->json('api_key');

    expect($newKey)->toBeString()->not->toBe('KEY-BEFORE-RESET');
    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => $newKey])->assertSuccessful();
});

it('shows uptime and disk usage computed by the models', function (): void {
    $device = Device::factory()->active()->create([
        'disks' => [
            ['name' => 'C:', 'mount_point' => 'C:\\', 'total_gb' => 100.0, 'available_gb' => 5.0],
            ['name' => 'D:', 'mount_point' => 'D:\\', 'total_gb' => 100.0, 'available_gb' => 80.0],
        ],
    ]);
    DeviceMetric::factory()->create(['device_id' => $device->id, 'uptime_seconds' => (2 * 86400) + (5 * 3600) + 600]);

    Livewire::actingAs(User::factory()->create())
        ->test(Overview::class, ['device' => $device])
        ->assertSee('2d 5h')
        ->assertSee('5.0 GB free of 100.0 GB')
        ->assertSee('95.0% used')
        ->assertSee('20.0% used')
        ->assertSeeHtml('var(--color-red-600)')
        ->assertSeeHtml('var(--color-blue-600)');
});

it('formats uptime like the device page always has', function (?int $seconds, ?string $expected): void {
    expect(DeviceMetric::factory()->make(['uptime_seconds' => $seconds])->uptimeForHumans())->toBe($expected);
})->with([
    'unknown' => [null, null],
    'seconds' => [45, '0m'],
    'minutes' => [300, '5m'],
    'hours' => [(3 * 3600) + 125, '3h 2m'],
    'days' => [(2 * 86400) + (5 * 3600) + 600, '2d 5h'],
]);

it('colours disk usage from the configured thresholds', function (float $availableGb, string $barColor): void {
    $device = Device::factory()->make(['disks' => [['name' => 'C:', 'total_gb' => 100.0, 'available_gb' => $availableGb]]]);

    expect($device->diskUsage()->first()['barColor'])->toBe($barColor);
})->with([
    'healthy' => [50.0, 'blue'],
    'filling up' => [20.0, 'amber'],
    'full' => [5.0, 'red'],
]);
