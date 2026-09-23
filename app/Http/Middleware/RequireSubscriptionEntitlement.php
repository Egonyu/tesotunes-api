<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscriptionEntitlement
{
    public function handle(Request $request, Closure $next, string $entitlement): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasSubscriptionEntitlement($entitlement)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current subscription does not include this feature.',
                'code' => 'subscription_upgrade_required',
                'entitlement' => $entitlement,
                'current_plan' => $user?->getEffectiveSubscriptionPlan()?->slug ?? 'free',
            ], 403);
        }

        return $next($request);
    }
}
