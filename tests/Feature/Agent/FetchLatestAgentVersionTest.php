<?php

declare(strict_types=1);

use App\Actions\Agent\FetchLatestAgentVersion;
use App\Events\LatestAgentVersionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => Event::fake([LatestAgentVersionChanged::class]));

it('caches the latest release without its v prefix', function (): void {
    Http::fake([config('agent.releases_url') => Http::response(['tag_name' => 'v0.5.1'])]);

    $version = app(FetchLatestAgentVersion::class)();

    expect($version)->toBe('0.5.1')
        ->and(Cache::get(config('agent.latest_version_cache_key')))->toBe('0.5.1');
    Http::assertSent(fn ($request): bool => $request->url() === config('agent.releases_url') && $request->hasHeader('Accept', 'application/json'));
});

it('accepts a tag without a v prefix', function (): void {
    Http::fake([config('agent.releases_url') => Http::response(['tag_name' => '0.6.0'])]);

    expect(app(FetchLatestAgentVersion::class)())->toBe('0.6.0');
});

it('keeps the previous version when the release check fails', function (mixed $response): void {
    Cache::forever(config('agent.latest_version_cache_key'), '0.5.0');
    Http::fake([config('agent.releases_url') => $response]);

    expect(app(FetchLatestAgentVersion::class)())->toBeNull()
        ->and(Cache::get(config('agent.latest_version_cache_key')))->toBe('0.5.0');
    Event::assertNotDispatched(LatestAgentVersionChanged::class);
})->with([
    'server error' => fn () => Http::response(['message' => 'boom'], 500),
    'rate limited' => fn () => Http::response(['message' => 'API rate limit exceeded'], 403),
    'missing tag' => fn () => Http::response(['name' => 'no tag here']),
    'empty tag' => fn () => Http::response(['tag_name' => '']),
]);

it('announces a new latest version only when it changes', function (): void {
    Http::fake([config('agent.releases_url') => Http::response(['tag_name' => 'v0.5.1'])]);

    app(FetchLatestAgentVersion::class)();
    app(FetchLatestAgentVersion::class)();

    Event::assertDispatchedTimes(LatestAgentVersionChanged::class, 1);
    Event::assertDispatched(LatestAgentVersionChanged::class, fn (LatestAgentVersionChanged $event): bool => $event->broadcastWith() === ['version' => '0.5.1']
        && collect($event->broadcastOn())->map->name->all() === ['private-devices']);
});
