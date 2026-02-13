<?php

namespace App\Modules\Sacco\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SaccoEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('sacco.enabled', false)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'SACCO module is currently disabled.',
                    'error' => 'MODULE_DISABLED',
                    'code' => 503
                ], 503);
            }

            abort(503, 'SACCO module is currently disabled. Please contact administrator.');
        }

        return $next($request);
    }
}
