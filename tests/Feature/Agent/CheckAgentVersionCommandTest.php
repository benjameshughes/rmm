<?php

declare(strict_types=1);

use App\Enums\AlertMetric;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Device;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

pest()->use(RefreshDatabase::class);

it('fetches the latest release and syncs outdated alerts for the fleet', function (): void {
    Http::fake([config('agent.releases_url') => Http::response(['tag_name' => 'v0.5.1'])]);
    $outdated = Device::factory()->active()->create(['agent_version' => '0.5.0']);
    $current = Device::factory()->active()->create(['agent_version' => '0.5.1']);
    Device::factory()->create(['agent_version' => '0.4.0']);
    $caughtUp = Device::factory()->active()->create(['agent_version' => '0.5.0']);
    $this->artisan('agent:check-version')->assertSuccessful()->run();
    $caughtUp->update(['agent_version' => '0.5.1']);

    $this->artisan('agent:check-version')
        ->expectsOutputToContain('Latest agent is 0.5.1. 1 of 3 devices are behind.')
        ->assertSuccessful();

    expect(Cache::get(config('agent.latest_version_cache_key')))->toBe('0.5.1');
    expect(Alert::query()->where('metric', AlertMetric::AgentOutdated)->unresolved()->pluck('device_id')->all())->toBe([$outdated->id]);
    expect(Alert::query()->where('device_id', $caughtUp->id)->sole()->status)->toBe(AlertStatus::Resolved);
    expect(Alert::query()->where('device_id', $current->id)->exists())->toBeFalse();
});

it('fails without touching alerts when the release check fails', function (): void {
    Http::fake([config('agent.releases_url') => Http::response([], 500)]);
    Device::factory()->active()->create(['agent_version' => '0.1.0']);

    $this->artisan('agent:check-version')->assertFailed();

    expect(Alert::query()->count())->toBe(0);
});

it('is scheduled hourly', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'agent:check-version'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
