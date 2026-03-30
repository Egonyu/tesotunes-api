<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs API requests & responses for monitoring and debugging.
 *
 * Captures: method, URI, status code, duration, user ID, IP, user agent.
 * Skips logging request/response bodies to avoid PII leakage and large payloads.
 * Slow requests (>2s) are logged at warning level.
 */
class ApiLoggingMiddleware
{
    /**
     * URIs that should not be logged (high frequency, low value).
     */
    protected array $excludedPaths = [
        'api/health',
        'api/ping',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        // Skip excluded paths
        if ($this->shouldSkip($request)) {
            return $response;
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $statusCode = $response->getStatusCode();

        $logData = [
            'method' => $request->method(),
            'uri' => $request->path(),
            'status' => $statusCode,
            'duration_ms' => $duration,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('observability_request_id'),
            'trace_id' => $request->attributes->get('observability_trace_id'),
        ];

        // Slow request warning (>2 seconds)
        if ($duration > 2000) {
            Log::channel('json')->warning('Slow API request', $logData);

            return $response;
        }

        // Server errors
        if ($statusCode >= 500) {
            Log::channel('json')->error('API server error', $logData);

            return $response;
        }

        // Client errors (4xx) at info level
        if ($statusCode >= 400) {
            Log::channel('json')->info('API client error', $logData);

            return $response;
        }

        // Successful requests at debug level
        Log::channel('json')->debug('API request', $logData);

        return $response;
    }

    protected function shouldSkip(Request $request): bool
    {
        foreach ($this->excludedPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
