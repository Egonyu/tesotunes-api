<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class WebhookRateLimiter
{
    /**
     * Handle an incoming request.
     *
     * Enforces an optional source-IP allowlist, then rate-limits incoming
     * webhook requests to prevent abuse.
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60): Response
    {
        if (! $this->isAllowedSource($request)) {
            Log::warning('Webhook rejected: source IP not in allowlist', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        $key = 'webhook:'.($request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'success' => false,
                'message' => 'Too many webhook requests.',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', $retryAfter);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    /**
     * Check the request IP against the configured webhook allowlist.
     * Returns true (fail-open) when no allowlist is configured so local and
     * CI traffic is never blocked; signature verification remains the primary
     * authenticity control downstream.
     */
    private function isAllowedSource(Request $request): bool
    {
        $allowlist = config('services.webhooks.ip_allowlist', []);

        if (! is_array($allowlist) || $allowlist === []) {
            return true;
        }

        $ip = $request->ip();

        if ($ip === null) {
            return false;
        }

        return IpUtils::checkIp($ip, $allowlist);
    }
}
