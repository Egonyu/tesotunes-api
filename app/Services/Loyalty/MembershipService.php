<?php

namespace App\Services\Loyalty;

use App\Models\Loyalty\LoyaltyCard;
use App\Models\Loyalty\LoyaltyCardMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function __construct(
        protected LoyaltyPointsService $pointsService,
    ) {}

    /**
     * Subscribe a user to a loyalty card tier.
     */
    public function subscribe(
        User $user,
        LoyaltyCard $card,
        string $tier,
        string $subscriptionType,
        ?string $paymentMethod = null,
        ?string $paymentTransactionId = null,
    ): LoyaltyCardMember {
        // Validate the card is active
        if (! $card->isActive()) {
            throw new \InvalidArgumentException('This loyalty card is not currently accepting members.');
        }

        // Validate tier exists
        $tierConfig = $card->tierConfig($tier);
        if (! $tierConfig) {
            throw new \InvalidArgumentException("Invalid tier: {$tier}");
        }

        // Check subscription type allowed
        if ($subscriptionType === 'monthly' && ! $card->allow_monthly) {
            throw new \InvalidArgumentException('Monthly subscriptions are not available for this card.');
        }
        if ($subscriptionType === 'yearly' && ! $card->allow_yearly) {
            throw new \InvalidArgumentException('Yearly subscriptions are not available for this card.');
        }

        // Check if user already has an active membership
        $existing = LoyaltyCardMember::where('user_id', $user->id)
            ->where('loyalty_card_id', $card->id)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('You already have an active membership for this loyalty card.');
        }

        $price = $card->tierPrice($tier, $subscriptionType);
        $expiresAt = $subscriptionType === 'yearly' ? now()->addYear() : now()->addMonth();

        return DB::transaction(function () use ($user, $card, $tier, $subscriptionType, $price, $expiresAt, $paymentMethod, $paymentTransactionId) {
            $member = LoyaltyCardMember::create([
                'loyalty_card_id' => $card->id,
                'user_id' => $user->id,
                'tier' => $tier,
                'subscription_type' => $subscriptionType,
                'price_paid' => $price,
                'currency' => 'UGX',
                'status' => 'active',
                'joined_at' => now(),
                'expires_at' => $expiresAt,
                'auto_renew' => $card->auto_renew,
                'payment_method' => $paymentMethod,
                'payment_transaction_id' => $paymentTransactionId,
                'lifetime_value' => $price,
            ]);

            // Increment the card's member count
            $card->increment('total_members');

            // Refresh user's points multiplier
            $this->pointsService->refreshMultiplier($user);

            return $member;
        });
    }

    /**
     * Cancel a membership (stays active until expiry date).
     */
    public function cancel(LoyaltyCardMember $membership): LoyaltyCardMember
    {
        if ($membership->status === 'cancelled') {
            throw new \InvalidArgumentException('This membership is already cancelled.');
        }

        $membership->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        // Safely decrement total_members (avoid underflow on unsigned column)
        $card = $membership->loyaltyCard;
        if ($card->total_members > 0) {
            $card->decrement('total_members');
        }

        // Refresh multiplier
        $this->pointsService->refreshMultiplier($membership->user);

        return $membership->fresh();
    }

    /**
     * Renew an existing membership.
     */
    public function renew(LoyaltyCardMember $membership, ?string $paymentTransactionId = null): LoyaltyCardMember
    {
        $card = $membership->loyaltyCard;
        $price = $card->tierPrice($membership->tier, $membership->subscription_type);
        $newExpiry = $membership->subscription_type === 'yearly'
            ? now()->addYear()
            : now()->addMonth();

        return DB::transaction(function () use ($membership, $price, $newExpiry, $paymentTransactionId) {
            $membership->update([
                'status' => 'active',
                'expires_at' => $newExpiry,
                'renewed_at' => now(),
                'renewal_reminder_sent' => false,
                'payment_transaction_id' => $paymentTransactionId,
                'total_renewals' => $membership->total_renewals + 1,
                'lifetime_value' => $membership->lifetime_value + $price,
                'price_paid' => $price,
            ]);

            return $membership->fresh();
        });
    }

    /**
     * Upgrade or downgrade a member's tier.
     */
    public function changeTier(LoyaltyCardMember $membership, string $newTier): LoyaltyCardMember
    {
        $card = $membership->loyaltyCard;
        $tierConfig = $card->tierConfig($newTier);

        if (! $tierConfig) {
            throw new \InvalidArgumentException("Invalid tier: {$newTier}");
        }

        $newPrice = $card->tierPrice($newTier, $membership->subscription_type);

        $membership->update([
            'tier' => $newTier,
            'price_paid' => $newPrice,
        ]);

        // Refresh multiplier
        $this->pointsService->refreshMultiplier($membership->user);

        return $membership->fresh();
    }
}
