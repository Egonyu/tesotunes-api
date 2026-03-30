<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevBypassAdminEmailVerification
{
    /**
     * In local dev, allow admin users to access the admin console without being blocked
     * by email verification gates. This intentionally does not run in tests or production.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isLocal() || app()->runningUnitTests()) {
            return $next($request);
        }

        // Lazily resolve the sanctum user even if the auth middleware isn't in the group.
        $user = $request->user() ?? auth('sanctum')->user();

        if (! $user) {
            return $next($request);
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin', 'super_admin'])) {
            if (empty($user->email_verified_at)) {
                // Avoid surprising DB writes in non-local environments; local-only is intentional.
                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();
            }
        }

        return $next($request);
    }
}
