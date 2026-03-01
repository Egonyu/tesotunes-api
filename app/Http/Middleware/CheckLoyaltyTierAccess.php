<?php

namespace App\Http\Middleware;

use App\Models\Loyalty\LoyaltyCardMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLoyaltyTierAccess
{
    /**
     * Ensure the authenticated user holds an active loyalty membership
     * at or above the required tier.
     *
     * Usage: middleware('loyalty.tier:silver')
     */
    public function handle(Request $request, Closure $next, string $requiredTier = 'bronze'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $tierLevels = config('loyalty.tier_levels', [
            'bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4,
        ]);

        $requiredLevel = $tierLevels[$requiredTier] ?? 1;

        // Find the user's highest active membership tier
        $highestMembership = LoyaltyCardMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->get()
            ->sortByDesc(fn ($m) => $tierLevels[$m->tier] ?? 0)
            ->first();

        $userTier = $highestMembership?->tier ?? 'none';
        $userLevel = $tierLevels[$userTier] ?? 0;

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
