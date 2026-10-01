<?php

declare(strict_types=1);

use App\Enums\DeviceStatus;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\Device;

it('authenticates a valid key', function (): void {
    $device = Device::factory()->withApiKey('KEY-VALID')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-VALID'])
        ->assertSuccessful();

    expect($device->fresh()->last_seen)->not->toBeNull();
});

it('rejects an invalid key', function (): void {
    Device::factory()->withApiKey('KEY-VALID')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-WRONG'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid or revoked device key.']);
});

it('rejects the stored hash used as a key', function (): void {
    Device::factory()->withApiKey('KEY-VALID')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => Device::hashApiKey('KEY-VALID')])
        ->assertUnauthorized();
});

it('rejects a valid key for a device that is not active', function (DeviceStatus $status): void {
    Device::factory()->withApiKey('KEY-INACTIVE')->create(['status' => $status]);

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-INACTIVE'])
        ->assertUnauthorized();
})->with([DeviceStatus::Pending, DeviceStatus::Revoked]);

it('authenticates a key issued but not yet claimed', function (): void {
    Device::factory()->awaitingKeyClaim('KEY-UNCLAIMED')->create();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-UNCLAIMED'])
        ->assertSuccessful();
});

it('locks out failed attempts from an IP after too many bad keys', function (): void {
    for ($i = 0; $i < AuthenticateDevice::MAX_FAILED_ATTEMPTS_PER_MINUTE; $i++) {
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => "KEY-GUESS-{$i}"])
            ->assertUnauthorized();
    }

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-GUESS-NEXT'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->postJson('/api/heartbeat')
        ->assertTooManyRequests();
});

it('never locks out a valid key sharing an IP with a flood of bad keys', function (): void {
    Device::factory()->withApiKey('KEY-VALID')->create();

    for ($i = 0; $i <= AuthenticateDevice::MAX_FAILED_ATTEMPTS_PER_MINUTE; $i++) {
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => "KEY-GUESS-{$i}"]);
    }

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-GUESS-NEXT'])
        ->assertTooManyRequests();

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-VALID'])
        ->assertSuccessful();
});

it('does not lock out other IPs when one IP is brute forcing', function (): void {
    Device::factory()->withApiKey('KEY-VALID')->create();

    for ($i = 0; $i <= AuthenticateDevice::MAX_FAILED_ATTEMPTS_PER_MINUTE; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/heartbeat', [], ['X-Agent-Key' => "KEY-GUESS-{$i}"]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-GUESS'])
        ->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-VALID'])
        ->assertSuccessful();
});

it('does not count successful authentications towards the lockout', function (): void {
    Device::factory()->withApiKey('KEY-VALID')->create();

    for ($i = 0; $i < AuthenticateDevice::MAX_FAILED_ATTEMPTS_PER_MINUTE + 5; $i++) {
        $this->getJson('/api/commands/pending', ['X-Agent-Key' => 'KEY-VALID']);
        $this->postJson('/api/metrics', ['cpu' => 1, 'ram' => 1], ['X-Agent-Key' => 'KEY-VALID'])
            ->assertSuccessful();
    }

    $this->postJson('/api/metrics', ['cpu' => 1, 'ram' => 1], ['X-Agent-Key' => 'KEY-WRONG'])
        ->assertUnauthorized();
});

it('throttles heartbeats per authenticated device rather than per IP', function (): void {
    Device::factory()->withApiKey('KEY-BUSY')->create();
    Device::factory()->withApiKey('KEY-QUIET')->create();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-BUSY'])->assertSuccessful();
    }

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-BUSY'])
        ->assertTooManyRequests()
        ->assertJson(['message' => 'Too many heartbeat requests. Please try again later.']);

    $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-QUIET'])->assertSuccessful();
});

it('rejects bad keys before the route throttle runs', function (): void {
    for ($i = 0; $i < 15; $i++) {
        $this->postJson('/api/heartbeat', [], ['X-Agent-Key' => 'KEY-BOGUS'])
            ->assertUnauthorized();
    }
});
