<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLoyaltyTierAccess
{
    /**
     * Handle an incoming request.
     *
     * Ensures the authenticated user meets the required loyalty tier.
     */
    public function handle(Request $request, Closure $next, string $requiredTier = 'bronze'): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tiers = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4, 'diamond' => 5];
        $userTier = $user->loyalty_tier ?? 'bronze';
        $userLevel = $tiers[$userTier] ?? 1;
        $requiredLevel = $tiers[$requiredTier] ?? 1;

        if ($userLevel < $requiredLevel) {
            return response()->json([
                'message' => "This feature requires {$requiredTier} tier or higher.",
                'current_tier' => $userTier,
                'required_tier' => $requiredTier,
            ], 403);
        }

        return $next($request);
    }
}
