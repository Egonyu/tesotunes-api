<?php

namespace App\Http\Middleware;

use App\Enums\Observability\SecurityEventType;
use App\Services\Observability\SecurityEvent;
use App\Services\Observability\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watches API responses for the unambiguous abuse signals that the audit
 * trail does not capture: rate-limit breaches (429) and probes of protected
 * resources (403). Emission is queued, so it adds no latency to the response.
 */
class MonitorApiAbuse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $status = $response->getStatusCode();

        if ($status === 429 || $status === 403) {
            $this->emit($request, $status);
        }

        return $response;
    }

    private function emit(Request $request, int $status): void
    {
        // Never let the security console's own traffic generate noise.
        if (str_contains(strtolower($request->path()), 'observability')) {
            return;
        }

        $type = $status === 429
            ? SecurityEventType::ApiRateLimitExceeded
            : SecurityEventType::ApiForbiddenProbe;

        $user = $request->user();

        SecurityEventRecorder::emit(
            SecurityEvent::of($type)
                ->summary(strtoupper($request->method()).' /'.ltrim($request->path(), '/').' → '.$status)
                ->actor($user ? 'user' : 'guest', $user?->id, $user?->email)
                ->fromRequest($request)
                ->attack($status === 429 ? 'rate_limit' : 'forbidden_probe', null)
                ->detail('status', $status),
        );
    }
}
