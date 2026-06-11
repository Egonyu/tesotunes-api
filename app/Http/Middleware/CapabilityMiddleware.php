<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on capability grants (see docs/architecture/CAPABILITIES.md).
 * Usage: ->middleware('capability:promoter') or 'capability:seller,promoter'
 * (user needs ANY of the listed capabilities). Platform admins always pass.
 */
class CapabilityMiddleware
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Account is suspended',
            ], 403);
        }

        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            return $next($request);
        }

        foreach ($capabilities as $name) {
            $capability = Capability::tryFrom($name);

            if ($capability && $user->hasCapability($capability)) {
                return $next($request);
            }
        }

        $labels = collect($capabilities)
            ->map(fn (string $name) => Capability::tryFrom($name)?->label() ?? $name)
            ->implode(' or ');

        return response()->json([
            'success' => false,
            'message' => "This action requires {$labels} access. Apply from your account settings.",
        ], 403);
    }
}
