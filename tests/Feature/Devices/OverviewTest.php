<?php

declare(strict_types=1);

use App\DTOs\Inventory\InventorySecurity;
use App\DTOs\Inventory\InventoryUsers;
use App\Livewire\Devices\Overview;
use App\Models\Alert;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceInventory;
use App\Models\DeviceMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->device = Device::factory()->active()->windows()->create(['hostname' => 'OFFICE-PC', 'system_inventoried_at' => now()->subHours(2)]);
});

it('draws CPU and RAM as two separate compact charts', function (): void {
    collect([40, 30, 20])->each(fn (int $minutesAgo) => DeviceMetric::factory()->create([
        'device_id' => $this->device->id,
        'cpu' => 25.0,
        'ram' => 60.0,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]));

    $html = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertViewHas('charts', fn (array $charts): bool => collect($charts)->pluck('title')->all() === ['CPU', 'RAM'])
        ->assertSee('CPU · Last 24 hours')
        ->assertSee('RAM · Last 24 hours')
        ->assertDontSee('CPU & RAM')
        ->html();

    expect(substr_count($html, '<ui-chart'))->toBe(2)
        ->and(substr_count($html, 'aspect-[3/1]'))->toBe(0);
});

it('leads with what is wrong with the PC: errors, device problems, security and restart', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-windows-health')
        ->assertSee('System inventory · Collected 2 hours ago.')
        ->assertSeeInOrder(['System errors', '2', 'Device problems', '2', 'Security', 'to check', 'Restart', 'Pending'])
        ->assertSeeInOrder(['The Dell TechHub service terminated unexpectedly.', 'Realtek USB Audio · MEDIA'])
        ->assertSeeHtml('href="'.route('devices.security', $this->device).'"')
        ->assertSeeHtml('href="'.route('devices.apps', $this->device).'#updates"')
        ->assertDontSee('Service tag');
});

it('shows the first few system errors and keeps the rest behind Show more', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id, 'data' => [
        'recent_errors' => collect(range(1, 7))->map(fn (int $n): array => ['level' => 'Error', 'provider' => 'disk', 'message' => "Bad block {$n}."])->all(),
    ]]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-errors-more')
        ->assertSee('Show 2 more')
        ->assertSee('Bad block 7.');
});

it('hides the device problems card when every device works', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id, 'data' => ['device_problems' => [], 'recent_errors' => []]]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-errors-clear')
        ->assertDontSee('Devices Windows says are not working');
});

it('shows only Run now until there is an inventory, and nothing on linux', function (): void {
    $this->device->forceFill(['system_inventoried_at' => null])->save();

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-inventory-control')
        ->assertSee('Not collected yet.')
        ->assertDontSeeHtml('data-system-status');

    $linux = Device::factory()->active()->linux()->create();
    DeviceInventory::factory()->create(['device_id' => $linux->id]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $linux])
        ->assertDontSeeHtml('data-windows-health');
});

it('shows the health tiles live once an inventory syncs', function (): void {
    $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-system-status');

    DeviceInventory::factory()->create(['device_id' => $this->device->id]);

    $overview->dispatch("echo-private:devices.{$this->device->id},SystemInventorySynced", ['deviceId' => $this->device->id])
        ->assertSeeHtml('data-system-status')
        ->assertSee('The Dell TechHub service terminated unexpectedly.');
});

it('shows a full page file as a red usage bar', function (): void {
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'swap_used_mib' => 9728.0, 'swap_total_mib' => 10240.0]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-swap-usage')
        ->assertSeeInOrder(['Page File', '95.0%', '9.5 GB / 10.0 GB'])
        ->assertSeeHtml('data-flux-progress')
        ->assertSeeHtml('var(--color-red-600)')
        ->assertSeeHtml('--flux-progress-percentage: 95%')
        ->assertSeeHtml('aria-valuenow="95"');
});

it('keeps the overview query count flat as commands, alerts and disks pile up', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id]);

    $queriesFor = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device]);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $report = function (int $disks): void {
        $metric = DeviceMetric::factory()->create(['device_id' => $this->device->id, 'recorded_at' => now()]);
        $metric->diskMetrics()->createMany(collect(range(1, $disks))->map(fn (int $index): array => ['mount_point' => "D{$index}:", 'total_gb' => 100.0, 'available_gb' => 50.0])->all());
    };

    $report(1);
    DeviceCommand::factory()->create(['device_id' => $this->device->id]);
    Alert::factory()->triggered()->create(['device_id' => $this->device->id]);
    $few = $queriesFor();

    $this->travel(1)->minute();
    $report(4);
    DeviceCommand::factory()->count(4)->create(['device_id' => $this->device->id]);
    Alert::factory()->triggered()->count(3)->create(['device_id' => $this->device->id]);
    $many = $queriesFor();

    expect($many)->toBe($few);
});

it('sums up the firewall profiles in one badge', function (array $profiles, string $value, string $color): void {
    $firewall = (new InventorySecurity(['firewall' => $profiles]))->summary()->firstWhere('label', 'Firewall');

    expect($firewall->value)->toBe($value)->and($firewall->color)->toBe($color);
})->with([
    'all on' => [[['name' => 'Domain', 'is_enabled' => true], ['name' => 'Public', 'is_enabled' => true]], 'On', 'green'],
    'some off' => [[['name' => 'Domain', 'is_enabled' => true], ['name' => 'Private', 'is_enabled' => false], ['name' => 'Public', 'is_enabled' => false]], 'Private, Public off', 'amber'],
    'all off' => [[['name' => 'Domain', 'is_enabled' => false]], 'Off', 'red'],
    'not reported' => [[], 'Not reporting', 'zinc'],
]);

it('reads BitLocker and Defender for the security strip', function (array $security, string $bitlocker, string $defender): void {
    $summary = (new InventorySecurity($security))->summary()->mapWithKeys(fn ($check): array => [$check->label => $check->value]);

    expect($summary['BitLocker'])->toBe($bitlocker)->and($summary['Defender'])->toBe($defender);
})->with([
    'protected' => [['bitlocker' => [['drive' => 'C:', 'is_system_drive' => true, 'protection_status' => 'On']], 'defender' => ['is_real_time_enabled' => true]], 'On', 'On'],
    'exposed' => [['bitlocker' => [['drive' => 'C:', 'is_system_drive' => true, 'protection_status' => 'Off']], 'defender' => ['is_real_time_enabled' => false]], 'Off', 'Off'],
    'unreported' => [[], 'Not available', 'Not reporting'],
]);

it('says who was signed in when the inventory ran', function (array $users, ?string $expected): void {
    expect((new InventoryUsers($users))->signedInForHumans())->toBe($expected);
})->with([
    'one user' => [['logged_on' => [['name' => 'OFFICE-PC\\sarah']]], 'OFFICE-PC\\sarah'],
    'nobody' => [['logged_on' => []], 'Nobody'],
    'not read' => [[], null],
]);
