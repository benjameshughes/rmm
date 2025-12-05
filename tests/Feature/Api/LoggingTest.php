<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

pest()->use(RefreshDatabase::class);

it('logs enroll and check attempts', function (): void {
    Log::spy();

    $this->postJson('/api/enroll', [
        'hostname' => 'TEST-PC',
    ])->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.enroll', \Mockery::on(function ($ctx) {
        return ($ctx['hostname'] ?? null) === 'TEST-PC';
    }))->once();

    $this->getJson('/api/check?hostname=TEST-PC')->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.check', \Mockery::on(function ($ctx) {
        return ($ctx['hostname'] ?? null) === 'TEST-PC';
    }))->atLeast()->once();
});

it('logs metrics unauthorized and success', function (): void {
    Log::spy();

    // Unauthorized (no key)
    $this->postJson('/api/metrics', [])->assertUnauthorized();
    Log::shouldHaveReceived('warning')->with('api.metrics.unauthorized', \Mockery::on(function ($ctx) {
        return ($ctx['reason'] ?? null) === 'missing_key';
    }))->once();

    // Authorized
    $device = Device::factory()->active()->create(['api_key' => 'KEY-LOG']);

    $this->withHeaders(['X-Agent-Key' => "KEY-LOG\n"]) // include newline to ensure trimming
        ->postJson('/api/metrics', [
            'cpu' => 10,
            'ram' => 20,
            'timestamp' => now()->toISOString(),
        ])->assertSuccessful();

    Log::shouldHaveReceived('info')->with('api.metrics', \Mockery::on(function ($ctx) use ($device) {
        return ($ctx['device_id'] ?? null) === $device->id;
    }))->once();
});

