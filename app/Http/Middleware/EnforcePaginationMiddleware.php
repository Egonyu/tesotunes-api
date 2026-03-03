<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a maximum `per_page` query parameter on all API requests.
 *
 * Prevents clients from requesting unbounded result sets by capping
 * `per_page` at a configurable maximum (default: 100 rows).
 * If no `per_page` is provided, a sensible default of 20 is injected.
 *
 * This acts as a global safety net — individual controllers can
 * additionally use the HasPagination trait for controller-specific limits.
 */
class EnforcePaginationMiddleware
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce on GET/HEAD requests (list endpoints)
        if (! $request->isMethodCacheable()) {
            return $next($request);
        }

        $perPage = $request->query('per_page');

        if ($perPage !== null) {
            $capped = min(max((int) $perPage, 1), self::MAX_PER_PAGE);
            $request->query->set('per_page', $capped);
        }

        // Ensure 'limit' parameter is also capped (some endpoints use it)
        $limit = $request->query('limit');
        if ($limit !== null) {
            $capped = min(max((int) $limit, 1), self::MAX_PER_PAGE);
            $request->query->set('limit', $capped);
        }

        return $next($request);
    }
}
