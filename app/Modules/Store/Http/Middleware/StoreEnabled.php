<?php

namespace App\Modules\Store\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: StoreEnabled
 * 
 * Ensures the Store module is enabled before allowing access to any store routes
 * Returns 503 Service Unavailable if module is disabled
 */
class StoreEnabled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('store.enabled', false)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store module is currently disabled.',
                    'error' => 'MODULE_DISABLED',
                    'module' => 'store'
                ], 503);
            }

            abort(503, 'Store module is currently unavailable.');
        }

        return $next($request);
    }
}
