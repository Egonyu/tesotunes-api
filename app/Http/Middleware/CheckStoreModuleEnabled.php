<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckStoreModuleEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('modules.store.enabled', false)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Store module is currently disabled.'
                ], 503);
            }

            abort(404);
        }

        return $next($request);
    }
}
