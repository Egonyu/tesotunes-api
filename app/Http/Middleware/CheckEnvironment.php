<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckEnvironment
{
    /**
     * Handle an incoming request.
     *
     * Blocks access to certain routes in production unless explicitly allowed.
     */
    public function handle(Request $request, Closure $next, string ...$environments): Response
    {
        if (! empty($environments) && ! in_array(app()->environment(), $environments)) {
            abort(403, 'This action is not available in the current environment.');
        }

        return $next($request);
    }
}
