<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignObservabilityContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?: Str::uuid());
        $traceId = (string) ($request->headers->get('X-Trace-Id') ?: Str::uuid());
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $request->attributes->set('observability_request_id', $requestId);
        $request->attributes->set('observability_trace_id', $traceId);
        $request->attributes->set('observability_session_id', $sessionId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
