<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class WebhookRateLimiter
{
    /**
     * Handle an incoming request.
     *
     * Rate-limits incoming webhook requests to prevent abuse.
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 60): Response
    {
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
}
