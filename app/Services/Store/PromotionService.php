<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Promotion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionService
{
    /**
     * Validate promotion eligibility
     */
    public function validatePromotion(Promotion $promotion, $userId, float $orderAmount): bool
    {
        // Check if promotion is active
        if ($promotion->status !== 'active') {
            return false;
        }

        // Check if promotion has started
        if ($promotion->starts_at > now()) {
            return false;
        }

        // Check if promotion has not expired
        if ($promotion->ends_at < now()) {
            return false;
        }

        // Check minimum purchase amount
        if ($promotion->minimum_purchase && $orderAmount < $promotion->minimum_purchase) {
            return false;
        }

        // Check if user has already used this promotion
        if ($promotion->max_uses_per_user) {
            $userUses = $promotion->redemptions()
                ->where('user_id', $userId)
                ->count();
            
            if ($userUses >= $promotion->max_uses_per_user) {
                return false;
            }
        }

        // Check if promotion has reached max redemptions
        if ($promotion->max_redemptions) {
            $totalUses = $promotion->redemptions()->count();
            
            if ($totalUses >= $promotion->max_redemptions) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate discount amount
     */
    public function calculateDiscount(Promotion $promotion, float $orderAmount): float
    {
        $discount = 0;

        if ($promotion->discount_type === 'percentage') {
            $discount = ($orderAmount * $promotion->discount_value) / 100;
        } else {
            $discount = $promotion->discount_value;
        }

        // Apply maximum discount limit if set
        if ($promotion->maximum_discount && $discount > $promotion->maximum_discount) {
            $discount = $promotion->maximum_discount;
        }

        // Discount cannot exceed order amount
        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return round($discount, 2);
    }

    /**
     * Redeem promotion
     */
    public function redeemPromotion(Promotion $promotion, $userId, $orderId, float $discountAmount): bool
    {
        return DB::transaction(function () use ($promotion, $userId, $orderId, $discountAmount) {
            // Create redemption record
            $promotion->redemptions()->create([
                'user_id' => $userId,
                'order_id' => $orderId,
                'discount_amount' => $discountAmount,
                'redeemed_at' => now(),
            ]);

            // Increment redemption count
            $promotion->increment('redemptions_count');

            // Log redemption
            Log::info("Promotion redeemed", [
                'promotion_id' => $promotion->id,
                'user_id' => $userId,
                'order_id' => $orderId,
                'discount_amount' => $discountAmount,
            ]);

            return true;
        });
    }

    /**
     * Check if promotion code is valid
     */
    public function validatePromoCode(string $code): ?Promotion
    {
        return Promotion::where('promo_code', $code)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->first();
    }

    /**
     * Get active promotions for user
     */
    public function getActivePromotions($userId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Promotion::where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());

        if ($userId) {
            // Filter out promotions user has already maxed out
            $query->whereDoesntHave('redemptions', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->havingRaw('COUNT(*) >= max_uses_per_user');
            });
        }

        return $query->get();
    }
}
