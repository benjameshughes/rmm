<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\StaffAccount;
use App\Models\Tag;
use App\Queries\StaffAccountQueries;

it('resolves direct, group and tag targets to distinct commandable Windows PCs', function (): void {
    $group = DeviceGroup::factory()->create();
    $tag = Tag::factory()->create();

    $direct = Device::factory()->windows()->active()->create(['hostname' => 'A-DIRECT']);
    $inGroup = Device::factory()->windows()->active()->create(['hostname' => 'B-GROUP', 'device_group_id' => $group->id]);
    $tagged = Device::factory()->windows()->active()->create(['hostname' => 'C-TAG']);
    $everywhere = Device::factory()->windows()->active()->create(['hostname' => 'D-ALL', 'device_group_id' => $group->id]);
    $linux = Device::factory()->linux()->active()->create(['device_group_id' => $group->id]);
    $monitorOnly = Device::factory()->windows()->active()->create(['is_monitor_only' => true, 'device_group_id' => $group->id]);
    $pending = Device::factory()->windows()->create(['device_group_id' => $group->id]);
    Device::factory()->windows()->active()->create(['hostname' => 'E-UNTARGETED']);

    $tag->devices()->attach([$tagged->id, $everywhere->id, $linux->id]);

    $account = StaffAccount::factory()->create();
    $account->devices()->attach([$direct->id, $everywhere->id, $monitorOnly->id]);
    $account->deviceGroups()->attach($group);
    $account->tags()->attach($tag);

    expect(app(StaffAccountQueries::class)->devices($account)->pluck('hostname')->all())
        ->toBe(['A-DIRECT', 'B-GROUP', 'C-TAG', 'D-ALL']);
});

it('resolves an account with no targets to no PCs', function (): void {
    Device::factory()->windows()->active()->create();

    expect(app(StaffAccountQueries::class)->devices(StaffAccount::factory()->create())->count())->toBe(0);
});

it('never resolves another account\'s targets', function (): void {
    $device = Device::factory()->windows()->active()->create();
    StaffAccount::factory()->create()->devices()->attach($device);

    expect(app(StaffAccountQueries::class)->devices(StaffAccount::factory()->create())->count())->toBe(0);
});
