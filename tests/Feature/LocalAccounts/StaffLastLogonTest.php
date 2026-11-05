<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Staff\Index;
use App\Models\Device;
use App\Models\DeviceLocalAccount;
use App\Models\StaffAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
});

it('shows each staff account\'s latest logon and the PC it was on', function (): void {
    $front = Device::factory()->windows()->active()->create(['hostname' => 'FRONT-DESK']);
    $back = Device::factory()->windows()->active()->create(['hostname' => 'BACK-OFFICE']);
    StaffAccount::factory()->create(['username' => 'sophie']);
    DeviceLocalAccount::factory()->for($front)->create(['username' => 'Sophie', 'last_logon_at' => '2026-11-04 08:15:00']);
    DeviceLocalAccount::factory()->for($back)->create(['username' => 'sophie', 'last_logon_at' => '2026-10-01 09:00:00']);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->assertSeeHtml('data-last-logon-device="FRONT-DESK"')
        ->assertDontSeeHtml('data-last-logon-device="BACK-OFFICE"');
});

it('says when a staff account has never signed in anywhere', function (): void {
    StaffAccount::factory()->create(['username' => 'sophie']);

    Livewire::actingAs($this->user)
        ->test(Index::class)
        ->assertSeeHtml('data-last-logon-never');
});
