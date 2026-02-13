<?php

namespace App\Modules\Store\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware: StoreOwner
 * 
 * Ensures the authenticated user owns the store being accessed
 */
class StoreOwner
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->route('store');

        if (!$store || $store->user_id !== auth()->id()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to this store.',
                    'error' => 'UNAUTHORIZED'
                ], 403);
            }

            abort(403, 'You do not have permission to access this store.');
        }

        return $next($request);
    }
}
