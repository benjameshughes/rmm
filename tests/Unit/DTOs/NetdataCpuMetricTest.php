<?php

declare(strict_types=1);

use App\DTOs\NetdataCpuMetric;

it('parses numeric input directly', function (): void {
    $metric = new NetdataCpuMetric(55.25);

    expect($metric->getUsagePercent())->toBe(55.25);
});

it('clamps numeric input to 0-100 range', function (): void {
    $highMetric = new NetdataCpuMetric(150.0);
    $lowMetric = new NetdataCpuMetric(-10.0);

    expect($highMetric->getUsagePercent())->toBe(100.0);
    expect($lowMetric->getUsagePercent())->toBe(0.0);
});

it('parses netdata json with idle dimension', function (): void {
    $json = json_encode([
        'labels' => ['time', 'user', 'system', 'idle'],
        'data' => [[1733430000, 12.0, 3.0, 85.0]],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBe(15.0); // 100 - 85
});

it('parses netdata jsonwrap format', function (): void {
    $json = json_encode([
        'result' => [
            'labels' => ['time', 'user', 'system', 'idle'],
            'data' => [[1733430000, 10.0, 5.0, 85.0]],
        ],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBe(15.0); // 100 - 85
});

it('uses fallback dimensions when idle is not present', function (): void {
    $usageJson = json_encode([
        'labels' => ['time', 'usage'],
        'data' => [[1733430000, 42.5]],
    ]);

    $busyJson = json_encode([
        'labels' => ['time', 'busy'],
        'data' => [[1733430000, 67.8]],
    ]);

    $usageMetric = new NetdataCpuMetric($usageJson);
    $busyMetric = new NetdataCpuMetric($busyJson);

    expect($usageMetric->getUsagePercent())->toBe(42.5);
    expect($busyMetric->getUsagePercent())->toBe(67.8);
});

it('handles multiple data rows by using last row', function (): void {
    $json = json_encode([
        'labels' => ['time', 'user', 'system', 'idle'],
        'data' => [
            [1733430000, 10.0, 5.0, 90.0],
            [1733430001, 15.0, 5.0, 80.0],
            [1733430002, 20.0, 5.0, 75.0],
        ],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBe(25.0); // Uses last row: 100 - 75
});

it('returns null for empty string', function (): void {
    $metric = new NetdataCpuMetric('');

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for null input', function (): void {
    $metric = new NetdataCpuMetric(null);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for invalid json', function (): void {
    $metric = new NetdataCpuMetric('not valid json {');

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for json without required structure', function (): void {
    $json = json_encode(['foo' => 'bar']);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null for json with empty data array', function (): void {
    $json = json_encode([
        'labels' => ['time', 'idle'],
        'data' => [],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null when labels is not an array', function (): void {
    $json = json_encode([
        'labels' => 'not an array',
        'data' => [[1733430000, 85.0]],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('returns null when idle value is not numeric', function (): void {
    $json = json_encode([
        'labels' => ['time', 'idle'],
        'data' => [[1733430000, 'not a number']],
    ]);

    $metric = new NetdataCpuMetric($json);

    expect($metric->getUsagePercent())->toBeNull();
});

it('handles string numeric input', function (): void {
    $metric = new NetdataCpuMetric('42.5');

    expect($metric->getUsagePercent())->toBe(42.5);
});
