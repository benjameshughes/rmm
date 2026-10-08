<?php

declare(strict_types=1);

use App\Actions\Script\SyncSystemScripts;
use App\Livewire\Devices\Header;
use App\Livewire\Devices\Index;
use App\Livewire\Software\Show;
use App\Livewire\Tags\Index as TagsIndex;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceSoftware;
use App\Models\Script;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

pest()->use(RefreshDatabase::class);

beforeEach(function (): void {
    app(SyncSystemScripts::class)();
    $this->user = User::factory()->create();
});

/**
 * The decoded x-on:click handler of the element that confirms before calling the given $wire method.
 */
function confirmTrigger(string $html, string $call): string
{
    preg_match_all('/x-on:click(?:\.stop)?="([^"]*)"/', $html, $handlers);

    return collect($handlers[1])
        ->map(fn (string $handler): string => html_entity_decode($handler, ENT_QUOTES))
        ->first(fn (string $handler): bool => str_contains($handler, "\$wire.{$call}"), '');
}

it('leaves no native browser confirm or alert dialogs in views or scripts', function (): void {
    $offenders = collect([...File::allFiles(resource_path('views')), ...File::allFiles(resource_path('js'))])
        ->filter(fn (SplFileInfo $file): bool => preg_match('/wire:confirm|(?<![\w.$-])(confirm|alert)\(/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('renders the shared confirm modal once on authenticated pages', function (): void {
    $html = $this->actingAs($this->user)->get(route('dashboard'))->assertSuccessful()->getContent();

    expect(substr_count($html, 'data-modal="confirm-action"'))->toBe(1)
        ->and($html)->toContain('x-on:confirm-action.window');
});

it('asks before restarting or powering off the ticked devices, counted in the browser, and still runs them', function (): void {
    $devices = Device::factory()->active()->count(2)->create();

    $page = Livewire::actingAs($this->user)->test(Index::class)
        ->set('selectedDevices', $devices->pluck('id')->map(fn (int $id): string => (string) $id)->all());

    expect(confirmTrigger($page->html(), 'bulkRestart()'))
        ->toContain("message: 'Restart ' + countLabel + '?'")
        ->not->toContain('danger: true')
        ->and(confirmTrigger($page->html(), 'bulkPowerOff()'))
        ->toContain("message: 'Power off ' + countLabel + '?'")
        ->toContain('danger: true');

    $page->call('bulkRestart')->assertDispatched('command-queued');

    expect(DeviceCommand::query()->pluck('script_id')->unique()->all())->toBe([Script::findSystem('restart')->id]);
});

it('asks before powering off a device from its header, and still powers it off', function (): void {
    $device = Device::factory()->active()->create(['hostname' => 'O\'NEIL-PC', 'last_seen' => now()]);

    $page = Livewire::actingAs($this->user)->test(Header::class, ['device' => $device]);

    expect(confirmTrigger($page->html(), 'powerOff()'))
        ->toContain("heading: 'Power off device'")
        ->toContain('message: '.Js::from("Are you sure you want to power off O'NEIL-PC?"))
        ->toContain('danger: true');

    $page->call('powerOff')->assertDispatched('command-queued');

    expect(DeviceCommand::query()->sole()->script_id)->toBe(Script::findSystem('shutdown')->id);
});

it('asks before uninstalling an app everywhere, and still uninstalls it', function (): void {
    $devices = Device::factory()->active()->windows()->count(2)->create(['agent_version' => '0.7.1', 'software_inventoried_at' => now()]);
    $devices->each(fn (Device $device) => DeviceSoftware::factory()->create(['device_id' => $device->id, 'package_id' => '7zip.7zip', 'name' => '7-Zip', 'source' => 'winget']));

    $page = Livewire::actingAs($this->user)->test(Show::class, ['packageId' => '7zip.7zip']);

    expect(confirmTrigger($page->html(), 'uninstallEverywhere()'))
        ->toContain("message: 'Uninstall 7-Zip from all 2 devices?'")
        ->toContain('danger: true');

    $page->call('uninstallEverywhere');

    expect(DeviceCommand::query()->count())->toBe(2);
});

it('keeps names with quotes from breaking the confirm handler', function (): void {
    $tag = Tag::factory()->create(['name' => 'Bob\'s "lab"']);

    $html = Livewire::actingAs($this->user)->test(TagsIndex::class)->html();

    expect(confirmTrigger($html, "delete({$tag->id})"))
        ->toContain('message: '.Js::from('Delete tag \''.$tag->name.'\'?'));
});

it('asks before updating the agent on the ticked devices and queues update-agent on each', function (): void {
    $devices = Device::factory()->active()->windows()->count(2)->create();

    $page = Livewire::actingAs($this->user)->test(Index::class)
        ->set('selectedDevices', $devices->pluck('id')->map(fn (int $id): string => (string) $id)->all());

    expect(confirmTrigger($page->html(), 'bulkUpdateAgent()'))
        ->toContain("message: 'Update the agent on ' + countLabel + ' to the latest release?'");

    $page->call('bulkUpdateAgent')->assertDispatched('command-queued');

    expect(DeviceCommand::query()->count())->toBe(2)
        ->and(DeviceCommand::query()->pluck('script_id')->unique()->all())->toBe([Script::findSystem('update-agent')->id]);
});
