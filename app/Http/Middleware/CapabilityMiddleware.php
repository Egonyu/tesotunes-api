<?php

namespace App\Http\Middleware;

use App\Enums\Capability;
use App\Models\User;
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

            if ($capability && ($user->hasCapability($capability) || $this->satisfiesLegacyRole($user, $capability))) {
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

    /**
     * Legacy fallback: the artist and label roles predate capability grants and
     * remain the source of truth for accounts that have not been backfilled.
     * Without this, flipping a route from role:artist to a capability gate would
     * lock out every artist who has no grant yet.
     */
    private function satisfiesLegacyRole(User $user, Capability $capability): bool
    {
        return match ($capability) {
            Capability::Artist => $user->hasAnyRole(['artist', 'Artist']),
            Capability::Label => $user->hasAnyRole(['label', 'Label']),
            default => false,
        };
    }
}
