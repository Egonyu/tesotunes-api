<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds deprecation headers to API responses for deprecated endpoints.
 *
 * Usage in routes:
 *   Route::get('/v1/old-endpoint', [Controller::class, 'method'])
 *       ->middleware('deprecated:2026-06-01,/api/v2/new-endpoint');
 *
 * Headers added:
 *   Deprecation: true
 *   Sunset: Sat, 01 Jun 2026 00:00:00 GMT
 *   Link: </api/v2/new-endpoint>; rel="successor-version"
 */
class DeprecationMiddleware
{
    public function handle(Request $request, Closure $next, ?string $sunsetDate = null, ?string $successor = null): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');

        if ($sunsetDate) {
            $sunset = new \DateTimeImmutable($sunsetDate);
            $response->headers->set('Sunset', $sunset->format(\DateTimeInterface::RFC7231));
        }

        if ($successor) {
            $response->headers->set('Link', "<{$successor}>; rel=\"successor-version\"");
        }

        return $response;
    }
}
