<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Events\LatestAgentVersionChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FetchLatestAgentVersion
{
    /**
     * Cache the newest agent release version. A failed check leaves the
     * previously cached version in place and returns null.
     */
    public function __invoke(): ?string
    {
        $response = Http::acceptJson()
            ->timeout(config('agent.release_check_timeout_seconds'))
            ->get(config('agent.releases_url'));

        $tag = $response->successful() ? $response->json('tag_name') : null;

        if (! is_string($tag) || $tag === '') {
            Log::warning('agent.release_check_failed', ['status' => $response->status()]);

            return null;
        }

        $version = ltrim($tag, 'vV');
        $previousVersion = Cache::get(config('agent.latest_version_cache_key'));

        Cache::forever(config('agent.latest_version_cache_key'), $version);

        if ($previousVersion !== $version) {
            LatestAgentVersionChanged::dispatch($version);
        }

        return $version;
    }
}
