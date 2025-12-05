<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('parses cpu and ram from netdata-like payloads', function (): void {
    $device = Device::factory()->active()->create(['api_key' => 'KEY-PARSE']);

    $cpuJson = json_encode([
        'labels' => ['time', 'user', 'system', 'idle'],
        'data' => [[1733430000, 12.0, 3.0, 85.0]],
    ]);

    $ramJson = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [[1733430000, 4096, 12288]],
    ]);

    $this->withHeaders(['X-Device-Key' => 'KEY-PARSE'])
        ->postJson('/api/metrics', [
            'cpu' => $cpuJson,
            'ram' => $ramJson,
        ])->assertSuccessful();

    $metric = DeviceMetric::firstOrFail();

    // CPU: 100 - idle (85) = 15
    expect($metric->cpu)->toBeFloat()->toBe(15.0);

    // RAM: used / (used+free) = 4096 / (4096 + 12288) = 0.25 => 25%
    expect($metric->ram)->toBeFloat()->toBe(25.0);
});
