<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyObservabilityCollectorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('services.observability.collector_token', env('OBSERVABILITY_COLLECTOR_TOKEN', ''));
        $providedToken = (string) $request->header('X-Observability-Token', '');

        if ($configuredToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
