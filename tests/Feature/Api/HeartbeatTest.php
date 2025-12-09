<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('api.heartbeat');
});

describe('heartbeat endpoint', function (): void {
    it('returns 401 when no API key is provided', function (): void {
        $response = $this->postJson('/api/heartbeat');

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Missing device key.']);
    });

    it('returns 401 for invalid API key', function (): void {
        $response = $this->postJson('/api/heartbeat', [], [
            'X-Agent-Key' => 'invalid-key',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Invalid or revoked device key.']);
    });

    it('returns 401 for revoked device', function (): void {
        Device::factory()->create([
            'api_key' => 'revoked-device-key',
            'status' => Device::STATUS_REVOKED,
        ]);

        $response = $this->postJson('/api/heartbeat', [], [
            'X-Agent-Key' => 'revoked-device-key',
        ]);

        $response->assertUnauthorized();
    });

    it('updates last_seen for valid device', function (): void {
        $device = Device::factory()->active()->create([
            'api_key' => 'valid-heartbeat-key',
            'last_seen' => now()->subHour(),
        ]);

        $response = $this->postJson('/api/heartbeat', [], [
            'X-Agent-Key' => 'valid-heartbeat-key',
        ]);

        $response->assertSuccessful()
            ->assertJsonStructure(['status', 'server_time'])
            ->assertJson(['status' => 'ok']);

        $device->refresh();
        expect($device->last_seen->diffInMinutes(now()))->toBeLessThan(1);
    });

    it('updates last_ip for valid device', function (): void {
        $device = Device::factory()->active()->create([
            'api_key' => 'ip-test-key',
            'last_ip' => '192.168.1.1',
        ]);

        $response = $this->postJson('/api/heartbeat', [], [
            'X-Agent-Key' => 'ip-test-key',
        ]);

        $response->assertSuccessful();

        $device->refresh();
        expect($device->last_ip)->toBe('127.0.0.1');
    });

    it('accepts X-Device-Key header as alternative', function (): void {
        Device::factory()->active()->create([
            'api_key' => 'device-key-header-test',
        ]);

        $response = $this->postJson('/api/heartbeat', [], [
            'X-Device-Key' => 'device-key-header-test',
        ]);

        $response->assertSuccessful();
    });

    it('is rate limited to 10 requests per minute', function (): void {
        Device::factory()->active()->create([
            'api_key' => 'rate-limit-test-key',
        ]);

        // First 10 should succeed
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/heartbeat', [], [
                'X-Agent-Key' => 'rate-limit-test-key',
            ]);
            $response->assertSuccessful();
        }

        // 11th should be rate limited
        $response = $this->postJson('/api/heartbeat', [], [
            'X-Agent-Key' => 'rate-limit-test-key',
        ]);
        $response->assertStatus(429);
    });
});
