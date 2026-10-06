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

it('shows the latest system inventory at a glance with a security strip', function (): void {
    DeviceInventory::factory()->create(['device_id' => $this->device->id]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-system-glance')
        ->assertSee('Collected 2 hours ago.')
        ->assertSeeInOrder(['Model', 'Dell Inc. Latitude 5440'])
        ->assertSeeInOrder(['Service tag', '7HQ2KZ3'])
        ->assertSee('13th Gen Intel(R) Core(TM) i5-1345U')
        ->assertSeeInOrder(['Windows', '23H2 · 22631.4317'])
        ->assertSeeInOrder(['Domain', 'Workgroup WORKGROUP'])
        ->assertSeeInOrder(['Signed in', 'OFFICE-PC\sarah'])
        ->assertSee('BitLocker · On')
        ->assertSee('Secure Boot · On')
        ->assertSee('Defender · On')
        ->assertSee('Firewall · Public off')
        ->assertSeeHtml('href="'.route('devices.system', $this->device).'"');
});

it('hides the inventory panel when there is no inventory to show', function (): void {
    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-system-glance')
        ->assertDontSee('Service tag');

    $linux = Device::factory()->active()->linux()->create();
    DeviceInventory::factory()->create(['device_id' => $linux->id]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $linux])
        ->assertDontSeeHtml('data-system-glance');
});

it('shows the panel live once an inventory syncs', function (): void {
    $overview = Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertDontSeeHtml('data-system-glance');

    DeviceInventory::factory()->create(['device_id' => $this->device->id]);

    $overview->dispatch("echo-private:devices.{$this->device->id},SystemInventorySynced", ['deviceId' => $this->device->id])
        ->assertSeeHtml('data-system-glance')
        ->assertSee('7HQ2KZ3');
});

it('shows a full page file as a red usage bar', function (): void {
    DeviceMetric::factory()->create(['device_id' => $this->device->id, 'swap_used_mib' => 9728.0, 'swap_total_mib' => 10240.0]);

    Livewire::actingAs($this->user)->test(Overview::class, ['device' => $this->device])
        ->assertSeeHtml('data-swap-usage')
        ->assertSeeInOrder(['Page File', '95.0%', '9.5 GB / 10.0 GB'])
        ->assertSeeHtml('bg-red-500" style="width: 95.0%"');
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
