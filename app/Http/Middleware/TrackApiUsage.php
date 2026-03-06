<?php

namespace App\Http\Middleware;

use App\Jobs\RecordApiUsageJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records API usage analytics to the database via a queued job.
 *
 * Lightweight — only dispatches a job with minimal data after the response is sent.
 * Skips high-frequency health/ping endpoints.
 */
class TrackApiUsage
{
    protected array $excludedPaths = [
        'api/health',
        'api/ping',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        if ($this->shouldSkip($request)) {
            return $response;
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        // Normalize endpoint to route pattern (e.g., api/songs/{song}) to avoid high cardinality
        $endpoint = $request->route()
            ? $request->route()->uri()
            : $request->path();

        RecordApiUsageJob::dispatch([
            'user_id' => $request->user()?->id,
            'method' => $request->method(),
            'endpoint' => $endpoint,
            'status_code' => $response->getStatusCode(),
            'response_time_ms' => $durationMs,
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            'requested_at' => now(),
        ]);

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
