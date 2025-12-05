<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    // Clear rate limiter between tests
    RateLimiter::clear('api.enroll');
    RateLimiter::clear('api.check');
    RateLimiter::clear('api.metrics');
});

describe('enrollment rate limiting', function (): void {
    it('allows up to 5 enrollment requests per minute per IP', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-001',
            'os' => 'Windows 11',
            'hardware_fingerprint' => 'FP-TEST-001',
        ];

        // First 5 requests should succeed
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/enroll', $payload);
            $response->assertSuccessful();
        }

        // 6th request should be rate limited
        $response = $this->postJson('/api/enroll', $payload);
        $response->assertStatus(429)
            ->assertJson([
                'message' => 'Too many enrollment attempts. Please try again later.',
            ]);
    });

    it('rate limits enrollment by IP address', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-002',
            'os' => 'Windows 11',
            'hardware_fingerprint' => 'FP-TEST-002',
        ];

        // Use up the limit from first IP (127.0.0.1 by default)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/enroll', $payload);
        }

        $this->postJson('/api/enroll', $payload)->assertStatus(429);

        // Clear rate limiter and verify rate limits are per-IP
        // In a real scenario, different IPs would have separate limits
        RateLimiter::clear('api.enroll:192.168.1.100');

        // Simulate request from different IP
        $response = $this->post('/api/enroll', $payload, [
            'REMOTE_ADDR' => '192.168.1.100',
        ]);
        $response->assertSuccessful();
    });
});

describe('check endpoint rate limiting', function (): void {
    it('allows up to 10 check requests per minute per IP', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-003',
            'hardware_fingerprint' => 'FP-TEST-003',
        ];

        // First 10 requests should succeed
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/check', $payload);
            $response->assertSuccessful();
        }

        // 11th request should be rate limited
        $response = $this->postJson('/api/check', $payload);
        $response->assertStatus(429)
            ->assertJson([
                'message' => 'Too many status check attempts. Please try again later.',
            ]);
    });

    it('rate limits check endpoint by IP address', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-004',
            'hardware_fingerprint' => 'FP-TEST-004',
        ];

        // Use up the limit from first IP (127.0.0.1 by default)
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/check', $payload);
        }

        $this->postJson('/api/check', $payload)->assertStatus(429);

        // Clear rate limiter for different IP to simulate separate limits
        RateLimiter::clear('api.check:10.0.0.50');

        // Simulate request from different IP
        $response = $this->post('/api/check', $payload, [
            'REMOTE_ADDR' => '10.0.0.50',
        ]);
        $response->assertSuccessful();
    });
});

describe('metrics rate limiting', function (): void {
    it('allows up to 120 metrics requests per minute per API key', function (): void {
        $device = Device::factory()->active()->create([
            'api_key' => 'RATE-LIMIT-TEST-KEY',
        ]);

        $payload = [
            'cpu' => 45.5,
            'ram' => 60.2,
        ];

        // First 120 requests should succeed
        for ($i = 0; $i < 120; $i++) {
            $response = $this->withHeaders(['X-Device-Key' => 'RATE-LIMIT-TEST-KEY'])
                ->postJson('/api/metrics', $payload);
            $response->assertSuccessful();
        }

        // 121st request should be rate limited
        $response = $this->withHeaders(['X-Device-Key' => 'RATE-LIMIT-TEST-KEY'])
            ->postJson('/api/metrics', $payload);
        $response->assertStatus(429)
            ->assertJson([
                'message' => 'Too many metric submissions. Please try again later.',
            ]);
    });

    it('rate limits metrics by API key not by IP', function (): void {
        $device1 = Device::factory()->active()->create([
            'api_key' => 'KEY-DEVICE-1',
        ]);

        $device2 = Device::factory()->active()->create([
            'api_key' => 'KEY-DEVICE-2',
        ]);

        $payload = [
            'cpu' => 45.5,
            'ram' => 60.2,
        ];

        // Use up the limit for device 1
        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders(['X-Device-Key' => 'KEY-DEVICE-1'])
                ->postJson('/api/metrics', $payload);
        }

        // Device 1 should be rate limited
        $response = $this->withHeaders(['X-Device-Key' => 'KEY-DEVICE-1'])
            ->postJson('/api/metrics', $payload);
        $response->assertStatus(429);

        // Device 2 with different key from same IP should still work
        $response = $this->withHeaders(['X-Device-Key' => 'KEY-DEVICE-2'])
            ->postJson('/api/metrics', $payload);
        $response->assertSuccessful();
    });

    it('rate limits by IP when no API key is provided', function (): void {
        $payload = [
            'cpu' => 45.5,
            'ram' => 60.2,
        ];

        // First request without key should fail auth (not rate limit)
        $response = $this->postJson('/api/metrics', $payload);
        $response->assertUnauthorized();

        // But should still consume rate limit attempts
        for ($i = 0; $i < 119; $i++) {
            $this->postJson('/api/metrics', $payload);
        }

        // After many attempts, should be rate limited before auth check
        $response = $this->postJson('/api/metrics', $payload);
        $response->assertStatus(429);
    });
});

describe('rate limit headers', function (): void {
    it('includes rate limit headers in responses', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-HEADERS',
            'hardware_fingerprint' => 'FP-HEADERS',
        ];

        $response = $this->postJson('/api/enroll', $payload);

        $response->assertSuccessful();
        expect($response->headers->has('X-RateLimit-Limit'))->toBeTrue();
        expect($response->headers->has('X-RateLimit-Remaining'))->toBeTrue();
    });

    it('includes retry-after header when rate limited', function (): void {
        $payload = [
            'hostname' => 'TEST-PC-RETRY',
            'hardware_fingerprint' => 'FP-RETRY',
        ];

        // Use up the limit
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/enroll', $payload);
        }

        // Next request should be rate limited with retry-after header
        $response = $this->postJson('/api/enroll', $payload);
        $response->assertStatus(429);
        expect($response->headers->has('Retry-After'))->toBeTrue();
        expect($response->headers->has('X-RateLimit-Reset'))->toBeTrue();
    });
});
