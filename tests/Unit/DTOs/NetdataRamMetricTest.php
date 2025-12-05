<?php

declare(strict_types=1);

use App\DTOs\NetdataRamMetric;

it('parses numeric input directly', function (): void {
    $metric = new NetdataRamMetric(71.5);

    expect($metric->getUsagePercent())->toBe(71.5);
});

it('clamps numeric input to 0-100 range', function (): void {
    $highMetric = new NetdataRamMetric(150.0);
    $lowMetric = new NetdataRamMetric(-10.0);

    expect($highMetric->getUsagePercent())->toBe(100.0);
    expect($lowMetric->getUsagePercent())->toBe(0.0);
});

it('parses netdata json with ram dimensions', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free', 'cached', 'buffers'],
        'data' => [[1733430000, 4096, 12288, 2048, 512]],
    ]);

    $metric = new NetdataRamMetric($json);

    // used / (used + free + cached + buffers) * 100
    // 4096 / (4096 + 12288 + 2048 + 512) * 100 = 4096 / 18944 * 100 = 21.62
    expect($metric->getUsagePercent())->toBe(21.62);
});

it('parses netdata jsonwrap format', function (): void {
    $json = json_encode([
        'result' => [
            'labels' => ['time', 'used', 'free'],
            'data' => [[1733430000, 8192, 8192]],
        ],
    ]);

    $metric = new NetdataRamMetric($json);

    // used / (used + free) * 100 = 8192 / 16384 * 100 = 50.0
    expect($metric->getUsagePercent())->toBe(50.0);
});

it('uses fallback usage dimension when calculation unavailable', function (): void {
    $json = json_encode([
        'labels' => ['time', 'usage'],
        'data' => [[1733430000, 65.25]],
    ]);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBe(65.25);
});

it('handles multiple data rows by using last row', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [
            [1733430000, 2048, 14336],
            [1733430001, 4096, 12288],
            [1733430002, 8192, 8192],
        ],
    ]);

    $metric = new NetdataRamMetric($json);

    // Uses last row: 8192 / (8192 + 8192) * 100 = 50.0
    expect($metric->getUsagePercent())->toBe(50.0);
});

it('handles available dimension in calculation', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'available'],
        'data' => [[1733430000, 6144, 10240]],
    ]);

    $metric = new NetdataRamMetric($json);

    // used / (used + available) * 100 = 6144 / 16384 * 100 = 37.5
    expect($metric->getUsagePercent())->toBe(37.5);
});

it('returns null for empty string', function (): void {
    $metric = new NetdataRamMetric('');

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for null input', function (): void {
    $metric = new NetdataRamMetric(null);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for invalid json', function (): void {
    $metric = new NetdataRamMetric('not valid json {');

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for json without required structure', function (): void {
    $json = json_encode(['foo' => 'bar']);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for json with empty data array', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [],
    ]);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null when labels is not an array', function (): void {
    $json = json_encode([
        'labels' => 'not an array',
        'data' => [[1733430000, 4096, 12288]],
    ]);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null when sum is zero', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [[1733430000, 0, 0]],
    ]);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null when used is zero', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [[1733430000, 0, 16384]],
    ]);

    $metric = new NetdataRamMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('handles string numeric input', function (): void {
    $metric = new NetdataRamMetric('65.5');

    expect($metric->getUsagePercent())->toBe(65.5);
});

it('ignores negative values in dimension calculation', function (): void {
    $json = json_encode([
        'labels' => ['time', 'used', 'free'],
        'data' => [[1733430000, 8192, -2048]],
    ]);

    $metric = new NetdataRamMetric($json);

    // Negative values are clamped to 0: used / (used + max(0, -2048)) * 100 = 8192 / 8192 * 100 = 100.0
    expect($metric->getUsagePercent())->toBe(100.0);
});
