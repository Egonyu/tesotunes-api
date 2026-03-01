<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyPoints;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoyaltyPointsService
{
    /**
     * Award points to a user (base amount × highest multiplier).
     */
    public function awardPoints(
        User $user,
        int $basePoints,
        string $source,
        ?int $sourceId = null,
        ?string $sourceType = null,
        ?string $description = null
    ): LoyaltyTransaction {
        return DB::transaction(function () use ($user, $basePoints, $source, $sourceId, $sourceType, $description) {
            $userPoints = $this->getOrCreatePoints($user);
            $multiplier = $this->getHighestMultiplier($user);
            $pointsAwarded = (int) round($basePoints * $multiplier);

            $userPoints->increment('balance', $pointsAwarded);
            $userPoints->increment('lifetime_earned', $pointsAwarded);
            $userPoints->refresh();

            return LoyaltyTransaction::create([
                'user_id' => $user->id,
                'type' => 'earned',
                'points' => $pointsAwarded,
                'balance_after' => $userPoints->balance,
                'source' => $source,
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'description' => $description ?? "Earned {$pointsAwarded} points from {$source}",
                'base_points' => $basePoints,
                'multiplier' => $multiplier,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Spend points (e.g., redeem reward, convert to credits).
     */
    public function spendPoints(
        User $user,
        int $points,
        string $source,
        ?int $sourceId = null,
        ?string $sourceType = null,
        ?string $description = null
    ): LoyaltyTransaction {
        return DB::transaction(function () use ($user, $points, $source, $sourceId, $sourceType, $description) {
            $userPoints = $this->getOrCreatePoints($user);

            if ($userPoints->balance < $points) {
                throw new \InvalidArgumentException('Insufficient points balance.');
            }

            $userPoints->decrement('balance', $points);
            $userPoints->increment('lifetime_spent', $points);
            $userPoints->refresh();

            return LoyaltyTransaction::create([
                'user_id' => $user->id,
                'type' => 'spent',
                'points' => -$points,
                'balance_after' => $userPoints->balance,
                'source' => $source,
                'source_id' => $sourceId,
                'source_type' => $sourceType,
                'description' => $description ?? "Spent {$points} points on {$source}",
                'base_points' => $points,
                'multiplier' => 1,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Convert loyalty points to credits.
     */
    public function convertToCredits(User $user, int $points): array
    {
        $rate = config('loyalty.points_to_credits_rate', 10);
        $credits = (int) floor($points / $rate);

        if ($credits <= 0) {
            throw new \InvalidArgumentException("Minimum {$rate} points required for conversion.");
        }

        $transaction = $this->spendPoints(
            $user,
            $points,
            'conversion',
            null,
            null,
            "Converted {$points} points to {$credits} credits"
        );

        // Award credits via the existing CreditService if available
        if (app()->bound(\App\Services\CreditService::class)) {
            app(\App\Services\CreditService::class)->awardCredits(
                $user,
                $credits,
                'loyalty_conversion',
                "Converted from {$points} loyalty points"
            );
        }

        return [
            'points_spent' => $points,
            'credits_earned' => $credits,
            'transaction' => $transaction,
        ];
    }

    /**
     * Refresh user's current multiplier based on active memberships.
     */
    public function refreshMultiplier(User $user): float
    {
        $multiplier = $this->getHighestMultiplier($user);
        $userPoints = $this->getOrCreatePoints($user);
        $userPoints->update(['current_multiplier' => $multiplier]);

        return $multiplier;
    }

    /**
     * Get user's points balance.
     */
    public function getBalance(User $user): array
    {
        $userPoints = $this->getOrCreatePoints($user);

        return [
            'balance' => $userPoints->balance,
            'lifetime_earned' => $userPoints->lifetime_earned,
            'lifetime_spent' => $userPoints->lifetime_spent,
            'current_multiplier' => (float) $userPoints->current_multiplier,
        ];
    }

    // ── Private ───────────────────────────────────────────────────

    private function getOrCreatePoints(User $user): LoyaltyPoints
    {
        return LoyaltyPoints::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'lifetime_earned' => 0, 'lifetime_spent' => 0, 'current_multiplier' => 1.00]
        );
    }

    private function getHighestMultiplier(User $user): float
    {
        $memberships = $user->loyaltyCardMemberships()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->with('loyaltyCard')
            ->get();

        if ($memberships->isEmpty()) {
            return 1.0;
        }

        return $memberships->map(function ($membership) {
            $tierConfig = $membership->loyaltyCard->tierConfig($membership->tier);
            if (! $tierConfig) {
                return 1.0;
            }

            // Benefits can be an associative array or a plain list
            $benefits = $tierConfig['benefits'] ?? [];
            if (is_array($benefits) && isset($benefits['loyalty_points_multiplier'])) {
                return (float) $benefits['loyalty_points_multiplier'];
            }

            // Check top-level key (some tier configs store multiplier at root)
            return (float) ($tierConfig['loyalty_points_multiplier'] ?? 1);
        })->max();
    }
}
