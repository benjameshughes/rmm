<?php

namespace App\Providers;

use App\Models\Device;
use Carbon\Carbon;
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

        Carbon::macro('inDisplayTimezone', fn (): Carbon => $this->copy()->setTimezone(config('app.display_timezone')));
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

            return Limit::perMinute(120)
                ->by($this->deviceThrottleKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many metric submissions. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('api.heartbeat', function (Request $request): Limit {

            return Limit::perMinute(10)
                ->by($this->deviceThrottleKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many heartbeat requests. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('api.commands', function (Request $request): Limit {

            return Limit::perMinute(60)
                ->by($this->deviceThrottleKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many command requests. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('api.power', function (Request $request): Limit {

            return Limit::perMinute(10)
                ->by($this->deviceThrottleKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many power events. Please try again later.',
                ], 429, $headers));
        });
    }

    /**
     * Device routes are throttled per authenticated device rather than by the
     * raw key header, so the limiter key is never attacker-controlled.
     */
    protected function deviceThrottleKey(Request $request): string
    {
        $device = $request->attributes->get('device');

        return $device instanceof Device ? 'device:'.$device->getKey() : 'ip:'.$request->ip();
    }
}
