<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

pest()->use(RefreshDatabase::class);

it('logs enroll and check attempts', function (): void {
    Log::spy();
    Log::shouldReceive('channel')->with('audit')->andReturnSelf();

    $this->postJson('/api/enroll', [
        'hostname' => 'TEST-PC',
        'hardware_fingerprint' => 'FP-LOGGING-TEST',
    ])->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.enroll', \Mockery::on(function ($ctx) {
        return ($ctx['hostname'] ?? null) === 'TEST-PC';
    }))->once();

    $this->getJson('/api/check?hostname=TEST-PC')->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.check', \Mockery::on(function ($ctx) {
        return ($ctx['hostname'] ?? null) === 'TEST-PC';
    }))->atLeast()->once();
});

it('logs only a fingerprint prefix and never the full fingerprint or key', function (): void {
    $fingerprint = str_repeat('a1b2c3d4', 8);
    Device::factory()->awaitingKeyClaim('KEY-SECRET-LOG')->create([
        'hostname' => 'SECRET-PC',
        'hardware_fingerprint' => $fingerprint,
    ]);

    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    $this->postJson('/api/enroll', ['hostname' => 'SECRET-PC', 'hardware_fingerprint' => $fingerprint])->assertSuccessful();
    $this->postJson('/api/check', ['hostname' => 'SECRET-PC', 'hardware_fingerprint' => $fingerprint])
        ->assertJsonPath('api_key', 'KEY-SECRET-LOG');
    $this->postJson('/api/check', ['hostname' => 'SECRET-PC', 'hardware_fingerprint' => 'zz'.$fingerprint])->assertSuccessful();

    $allLogs = implode("\n", $logged);

    expect($logged)->not->toBeEmpty()
        ->and($allLogs)->toContain('"fingerprint_prefix":"a1b2c3d4"')
        ->and($allLogs)->not->toContain($fingerprint)
        ->and($allLogs)->not->toContain('KEY-SECRET-LOG');
});

it('logs metrics success', function (): void {
    Log::spy();
    Log::shouldReceive('channel')->with('audit')->andReturnSelf();

    $device = Device::factory()->active()->withApiKey('KEY-LOG')->create();

    $this->withHeaders(['X-Agent-Key' => "KEY-LOG\n"])
        ->postJson('/api/metrics', [
            'cpu' => 10,
            'ram' => 20,
            'timestamp' => now()->toISOString(),
        ])->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.metrics', \Mockery::on(function ($ctx) use ($device) {
        return ($ctx['device_id'] ?? null) === $device->id;
    }))->once();
});

it('rejects metrics without device key via middleware', function (): void {
    $this->postJson('/api/metrics', [])->assertUnauthorized();
});

it('logs failed device authentication with a reason but never the presented key', function (): void {
    Log::spy();

    $this->postJson('/api/metrics')->assertUnauthorized();
    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'NOT-A-REAL-KEY'])->assertUnauthorized();

    Log::shouldHaveReceived('warning')->with('api.device_auth.failed', \Mockery::on(
        fn (array $context): bool => $context['reason'] === 'missing_key' && $context['path'] === 'api/metrics'
    ))->once();

    Log::shouldHaveReceived('warning')->with('api.device_auth.failed', \Mockery::on(
        fn (array $context): bool => $context['reason'] === 'invalid_or_revoked' && ! in_array('NOT-A-REAL-KEY', $context, true)
    ))->once();
});
