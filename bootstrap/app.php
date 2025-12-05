<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Configure rate limiters for API endpoints
        RateLimiter::for('api.enroll', function (Request $request): Limit {
            // 5 enrollments per minute per IP - devices shouldn't enroll frequently
            // Block for 10 minutes if limit exceeded (abuse prevention)
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function (Request $request, array $headers): \Illuminate\Http\JsonResponse {
                    return response()->json([
                        'message' => 'Too many enrollment attempts. Please try again later.',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('api.check', function (Request $request): Limit {
            // 10 checks per minute per IP - agents poll every 10 seconds while pending
            // This allows some flexibility for multiple devices from same IP
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function (Request $request, array $headers): \Illuminate\Http\JsonResponse {
                    return response()->json([
                        'message' => 'Too many status check attempts. Please try again later.',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('api.metrics', function (Request $request): Limit {
            // 120 requests per minute per API key (2 per second)
            // Agents send metrics every minute, this allows good buffer room
            $apiKey = $request->header('X-Device-Key') ?? $request->header('X-Agent-Key') ?? $request->ip();

            return Limit::perMinute(120)
                ->by($apiKey)
                ->response(function (Request $request, array $headers): \Illuminate\Http\JsonResponse {
                    return response()->json([
                        'message' => 'Too many metric submissions. Please try again later.',
                    ], 429, $headers);
                });
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
