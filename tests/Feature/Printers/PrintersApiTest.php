<?php

declare(strict_types=1);

use App\Events\DeviceUpdated;
use App\Events\PrintersReported;
use App\Models\Device;
use App\Models\DevicePrinter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * One raw printer as the agent sends it.
 *
 * @param  array<int, array<string, mixed>>  $jobs
 */
function rawPrinter(string $name, int $status = 0, array $jobs = []): array
{
    return [
        'name' => $name,
        'port_name' => 'USB001',
        'driver_name' => 'ZDesigner GK420d',
        'status' => $status,
        'attributes' => 0x840,
        'jobs_count' => count($jobs),
        'jobs' => $jobs,
    ];
}

function rawJob(int $id, int $status = 0, ?string $submitted = null): array
{
    return [
        'id' => $id,
        'document' => "Label {$id}",
        'user_name' => 'warehouse',
        'status' => $status,
        'status_text' => null,
        'submitted' => $submitted ?? now()->toIso8601ZuluString(),
        'total_pages' => 1,
        'pages_printed' => 0,
        'size' => 1234,
        'position' => $id,
        'priority' => 1,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $printers
 */
function postPrinters(array $printers, array $overrides = [], string $apiKey = 'PRINTER-KEY'): TestResponse
{
    return test()->postJson('/api/printers', [
        'hostname' => 'LABEL-PC-1',
        'agent_version' => '0.9.0',
        'trigger' => 'change',
        'collected_at' => now()->toIso8601ZuluString(),
        'spooler_available' => true,
        'printers' => $printers,
        ...$overrides,
    ], ['X-Agent-Key' => $apiKey]);
}

beforeEach(function (): void {
    RateLimiter::clear('api.metrics');
    $this->freezeSecond();
    $this->device = Device::factory()->windows()->withApiKey('PRINTER-KEY')->create(['hostname' => 'LABEL-PC-1', 'last_seen' => now()]);
});

it('refuses a request without a valid device key', function (): void {
    $this->postJson('/api/printers', ['printers' => []])->assertUnauthorized()->assertJson(['message' => 'Missing device key.']);
    postPrinters([], apiKey: 'wrong-key')->assertUnauthorized()->assertJson(['message' => 'Invalid or revoked device key.']);
});

it('validates the snapshot with readable messages', function (array $overrides, string $field): void {
    postPrinters([rawPrinter('Zebra')], $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing trigger' => [['trigger' => null], 'trigger'],
    'unknown trigger' => [['trigger' => 'whenever'], 'trigger'],
    'bad date' => [['collected_at' => 'yesterday-ish'], 'collected_at'],
    'no spooler flag' => [['spooler_available' => null], 'spooler_available'],
    'no printers list' => [['printers' => null], 'printers'],
    'printer without name' => [['printers' => [['status' => 0, 'jobs_count' => 0, 'jobs' => []]]], 'printers.0.name'],
    'text status' => [['printers' => [['name' => 'Zebra', 'status' => 'Offline', 'jobs_count' => 0, 'jobs' => []]]], 'printers.0.status'],
    'duplicate printer' => [['printers' => [rawPrinter('Zebra'), rawPrinter('Zebra')]], 'printers.1.name'],
    'job without id' => [['printers' => [['name' => 'Zebra', 'status' => 0, 'jobs_count' => 1, 'jobs' => [['status' => 0]]]]], 'printers.0.jobs.0.id'],
]);

it('stores the latest snapshot of each printer and stamps the report', function (): void {
    postPrinters([rawPrinter('Zebra GK420d - ZPL', 0, [rawJob(7)]), rawPrinter('XEROX3335 PCL6')])
        ->assertSuccessful()
        ->assertJson(['message' => 'Printers accepted.']);

    $zebra = DevicePrinter::query()->where('name', 'Zebra GK420d - ZPL')->sole();

    expect($this->device->printers()->pluck('name')->all())->toBe(['XEROX3335 PCL6', 'Zebra GK420d - ZPL'])
        ->and($zebra->queue()->jobs->first()->document)->toBe('Label 7')
        ->and($zebra->queue()->portName)->toBe('USB001')
        ->and($zebra->problem_since)->toBeNull()
        ->and($this->device->fresh()->printers_reported_at->equalTo(now()))->toBeTrue();
});

it('drops software printers by name pattern', function (): void {
    postPrinters([
        rawPrinter('Microsoft Print to PDF'),
        rawPrinter('Microsoft XPS Document Writer'),
        rawPrinter('OneNote (Desktop)'),
        rawPrinter('Send To OneNote 2016'),
        rawPrinter('Fax'),
        rawPrinter('Warehouse'),
    ])->assertSuccessful();

    expect($this->device->printers()->pluck('name')->all())->toBe(['Warehouse']);
});

it('forgets printers the PC no longer has', function (): void {
    postPrinters([rawPrinter('Zebra'), rawPrinter('Epson')]);
    postPrinters([rawPrinter('Epson')]);

    expect($this->device->printers()->pluck('name')->all())->toBe(['Epson']);
});

it('keeps the last known printers while the spooler is down and marks the spooler', function (): void {
    postPrinters([rawPrinter('Zebra')]);
    postPrinters([], ['spooler_available' => false])->assertSuccessful();

    $device = $this->device->fresh();
    expect($device->printers()->pluck('name')->all())->toBe(['Zebra'])
        ->and($device->spooler_down_since->equalTo(now()))->toBeTrue();

    postPrinters([rawPrinter('Zebra')]);
    expect($this->device->fresh()->spooler_down_since)->toBeNull();
});

it('tells the device page about every snapshot and fleet pages only when a problem starts or clears', function (): void {
    Event::fake([PrintersReported::class, DeviceUpdated::class]);
    $fleetBroadcasts = fn (): int => Event::dispatched(DeviceUpdated::class)->filter(fn (array $dispatch): bool => $dispatch[0]->broadcastWhen())->count();

    postPrinters([rawPrinter('Zebra')]);
    Event::assertDispatched(PrintersReported::class, fn (PrintersReported $event): bool => $event->deviceId === $this->device->id);
    expect($fleetBroadcasts())->toBe(1);

    postPrinters([rawPrinter('Zebra')]);
    Event::assertDispatchedTimes(PrintersReported::class, 2);
    expect($fleetBroadcasts())->toBe(1);

    postPrinters([rawPrinter('Zebra', 0x80)]);
    expect($fleetBroadcasts())->toBe(2);

    postPrinters([rawPrinter('Zebra')]);
    expect($fleetBroadcasts())->toBe(3);
});

it('broadcasts PrintersReported on the device channel only', function (): void {
    $channels = collect((new PrintersReported(5))->broadcastOn())->map(fn ($channel): string => $channel->name)->all();

    expect($channels)->toBe(['private-devices.5']);
});
