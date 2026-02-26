<?php

namespace App\Services\Loyalty;

use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\Loyalty\LoyaltyReward;
use App\Models\Loyalty\LoyaltyRewardRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public function __construct(
        protected LoyaltyPointsService $pointsService,
    ) {}

    /**
     * Get rewards available to a member based on their tier.
     */
    public function getAvailableRewards(LoyaltyCardMember $membership): \Illuminate\Database\Eloquent\Collection
    {
        return LoyaltyReward::where('loyalty_card_id', $membership->loyalty_card_id)
            ->active()
            ->available()
            ->forTier($membership->tier)
            ->orderBy('type')
            ->get();
    }

    /**
     * Redeem a reward for an authenticated member.
     */
    public function redeemReward(User $user, LoyaltyReward $reward): LoyaltyRewardRedemption
    {
        return DB::transaction(function () use ($user, $reward) {
            // Find the user's active membership for this card
            $membership = LoyaltyCardMember::where('user_id', $user->id)
                ->where('loyalty_card_id', $reward->loyalty_card_id)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();

            if (!$membership) {
                throw new \InvalidArgumentException('You do not have an active membership for this loyalty card.');
            }

            // Tier check
            if (!$reward->canBeRedeemedByTier($membership->tier)) {
                throw new \InvalidArgumentException(
                    "This reward requires {$reward->required_tier} tier or higher. Your tier: {$membership->tier}."
                );
            }

            // Availability check
            if (!$reward->isAvailable()) {
                throw new \InvalidArgumentException('This reward is no longer available.');
            }

            // Check if user already redeemed this reward
            $alreadyRedeemed = LoyaltyRewardRedemption::where('loyalty_reward_id', $reward->id)
                ->where('user_id', $user->id)
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($alreadyRedeemed) {
                throw new \InvalidArgumentException('You have already redeemed this reward.');
            }

            // Create redemption
            $redemption = LoyaltyRewardRedemption::create([
                'loyalty_reward_id'      => $reward->id,
                'user_id'                => $user->id,
                'loyalty_card_member_id' => $membership->id,
                'status'                 => $this->shouldAutoFulfil($reward) ? 'fulfilled' : 'pending',
                'fulfilled_at'           => $this->shouldAutoFulfil($reward) ? now() : null,
            ]);

            // Increment redemption counter
            $reward->increment('current_redemptions');

            // Deduct points if the reward has a points cost
            if ($reward->points_amount && $reward->points_amount > 0) {
                $this->pointsService->spendPoints(
                    user: $user,
                    points: $reward->points_amount,
                    source: 'reward_redemption',
                    sourceId: $reward->id,
                    sourceType: 'loyalty_reward',
                    description: "Redeemed reward: {$reward->name}",
                );
            }

            return $redemption;
        });
    }

    /**
     * Cancel a reward redemption.
     */
    public function cancelRedemption(LoyaltyRewardRedemption $redemption): LoyaltyRewardRedemption
    {
        if ($redemption->status === 'fulfilled') {
            throw new \InvalidArgumentException('Cannot cancel a fulfilled redemption.');
        }

        $redemption->update(['status' => 'cancelled']);
        $redemption->reward->decrement('current_redemptions');

        return $redemption->fresh();
    }

    /**
     * Fulfil a pending redemption (admin/artist action).
     */
    public function fulfilRedemption(LoyaltyRewardRedemption $redemption, ?string $notes = null): LoyaltyRewardRedemption
    {
        $redemption->update([
            'status'           => 'fulfilled',
            'fulfilled_at'     => now(),
            'fulfilment_notes' => $notes,
        ]);

        return $redemption->fresh();
    }

    // ── Private ───────────────────────────────────────────────────

    /**
     * Content and points rewards auto-fulfil; experiences & merch need manual fulfilment.
     */
    private function shouldAutoFulfil(LoyaltyReward $reward): bool
    {
        return in_array($reward->type, ['content', 'points', 'discount']);
    }
}
