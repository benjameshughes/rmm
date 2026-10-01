<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateDevice
{
    public const int MAX_FAILED_ATTEMPTS_PER_MINUTE = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Agent-Key') ?? $request->header('X-Device-Key');
        $hasKey = is_string($apiKey) && trim($apiKey) !== '';

        $device = $hasKey ? Device::findActiveByApiKey(trim($apiKey)) : null;

        if ($device !== null) {
            $request->attributes->set('device', $device);

            return $next($request);
        }

        return $this->rejectFailedAttempt($request, $hasKey);
    }

    /**
     * Failed attempts are counted per IP so a flood of bad keys is cut off, while a
     * valid key is never locked out by someone else's failures behind the same IP.
     */
    private function rejectFailedAttempt(Request $request, bool $hasKey): Response
    {
        $throttleKey = self::throttleKey($request);
        $isLockedOut = RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_ATTEMPTS_PER_MINUTE);

        Log::warning('api.device_auth.failed', [
            'reason' => match (true) {
                $isLockedOut => 'locked_out',
                $hasKey => 'invalid_or_revoked',
                default => 'missing_key',
            },
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        if ($isLockedOut) {
            return response()->json(
                ['message' => 'Too many failed authentication attempts. Please try again later.'],
                429,
                ['Retry-After' => RateLimiter::availableIn($throttleKey)],
            );
        }

        RateLimiter::hit($throttleKey, 60);

        return response()->json(['message' => $hasKey ? 'Invalid or revoked device key.' : 'Missing device key.'], 401);
    }

    public static function throttleKey(Request $request): string
    {
        return 'device-auth-failures:'.$request->ip();
    }
}
