<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets appropriate Cache-Control headers on API responses.
 *
 * - Public GET endpoints (no auth): cacheable by CDN for 60s, stale-while-revalidate for 120s
 * - Authenticated GET endpoints: private cache, 60s max-age (browser only, no CDN)
 * - Mutating requests (POST/PUT/PATCH/DELETE): no-store
 *
 * Individual routes can override by setting their own Cache-Control header
 * before this middleware runs (e.g. in the controller).
 */
class CacheHeadersMiddleware
{
    /**
     * Endpoints that should never be cached (even on GET).
     */
    private const NO_CACHE_PATTERNS = [
        'api/auth/*',
        'api/user',
        'api/payments/*',
        'api/webhooks/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Don't override if controller already set Cache-Control
        if ($response->headers->has('Cache-Control')
            && $response->headers->get('Cache-Control') !== 'no-cache, private') {
            return $response;
        }

        // Non-GET/HEAD requests are never cacheable
        if (! $request->isMethodCacheable()) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        // Skip caching for sensitive endpoints
        foreach (self::NO_CACHE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                $response->headers->set('Cache-Control', 'no-store');

                return $response;
            }
        }

        // Error responses should not be cached
        if ($response->getStatusCode() >= 400) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        // Authenticated requests: private cache only (browser, not CDN)
        if ($request->bearerToken() || $request->user()) {
            $response->headers->set(
                'Cache-Control',
                'private, max-age=60, must-revalidate'
            );

            return $response;
        }

        // Public GET endpoints: CDN-cacheable
        $response->headers->set(
            'Cache-Control',
            'public, max-age=60, s-maxage=120, stale-while-revalidate=120'
        );
        $response->headers->set('Vary', 'Accept, Authorization');

        return $response;
    }
}
