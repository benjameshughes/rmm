<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api.enroll', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many enrollment attempts. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('api.check', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many status check attempts. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('api.metrics', function (Request $request): Limit {
            $apiKey = $request->header('X-Device-Key') ?? $request->header('X-Agent-Key') ?? $request->ip();

            return Limit::perMinute(120)
                ->by($apiKey)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many metric submissions. Please try again later.',
                ], 429, $headers));
        });
    }
}
