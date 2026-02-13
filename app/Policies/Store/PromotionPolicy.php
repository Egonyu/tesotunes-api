<?php

namespace App\Policies\Store;

use App\Modules\Store\Models\Promotion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PromotionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?User $user, Promotion $promotion): bool
    {
        // Public can view active promotions
        if ($promotion->status === 'active' && 
            $promotion->starts_at <= now() && 
            $promotion->ends_at >= now()) {
            return true;
        }

        // Owner and admins can view any promotion
        return $user && (
            $promotion->artist_id === $user->id ||
            $user->hasAnyRole(['admin', 'super_admin'])
        );
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Only artists with active stores can create promotions
        return $user->hasRole('artist') && 
               $user->stores()->where('status', 'active')->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Promotion $promotion): bool
    {
        // Can't update after it's started
        if ($promotion->starts_at <= now()) {
            return false;
        }

        return $promotion->artist_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Promotion $promotion): bool
    {
        return $promotion->artist_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Promotion $promotion): bool
    {
        return $promotion->artist_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Promotion $promotion): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can approve the promotion.
     */
    public function approve(User $user, Promotion $promotion): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can redeem the promotion.
     */
    public function redeem(User $user, Promotion $promotion): bool
    {
        // Check if promotion is active and within date range
        if ($promotion->status !== 'active' || 
            $promotion->starts_at > now() || 
            $promotion->ends_at < now()) {
            return false;
        }

        // Check if max redemptions reached
        if ($promotion->max_redemptions && 
            $promotion->redemptions_count >= $promotion->max_redemptions) {
            return false;
        }

        // Check user-specific redemption limit
        if ($promotion->max_per_user) {
            $userRedemptions = $promotion->redemptions()
                ->where('user_id', $user->id)
                ->count();
            
            if ($userRedemptions >= $promotion->max_per_user) {
                return false;
            }
        }

        return true;
    }
}
