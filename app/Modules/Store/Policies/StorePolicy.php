<?php

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Store;

class StorePolicy
{
    /**
     * Determine if the user can view any stores.
     */
    public function viewAny(User $user): bool
    {
        return true; // Public browsing allowed
    }

    /**
     * Determine if the user can view the store.
     */
    public function view(?User $user, Store $store): bool
    {
        // Guest users can only view active stores
        if (!$user) {
            return $store->status === Store::STATUS_ACTIVE;
        }

        // Owners and admins can view
        if ($user->id === $store->user_id) {
            return true;
        }

        if ($user->hasAnyRole(['admin', 'super_admin'])) {
            return true;
        }

        // Public can view active stores
        return $store->status === Store::STATUS_ACTIVE;
    }

    /**
     * Determine if the user can create stores.
     */
    public function create(User $user): bool
    {
        // Check if module is enabled
        if (!config('store.enabled', false)) {
            return false;
        }

        // Check if user already has a store
        if ($user->store()->exists()) {
            return false;
        }

        // Check email verification
        if (!$user->email_verified_at) {
            return false;
        }

        // Check artists_only setting (check both config keys for backward compatibility)
        if (config('store.stores.artists_only', false) || config('modules.store.artists_only', false)) {
            return $user->hasRole('artist');
        }

        // Check if user is artist or if user stores are allowed
        if (!$user->hasRole('artist') && !config('store.stores.allow_user_stores', false)) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can update the store.
     */
    public function update(User $user, Store $store): bool
    {
        // Owner can update
        if ($user->id === $store->user_id) {
            return true;
        }

        // Admins can update
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can delete the store.
     */
    public function delete(User $user, Store $store): bool
    {
        // Only owner can delete (not admins)
        return $user->id === $store->user_id;
    }

    /**
     * Determine if the user can restore the store.
     */
    public function restore(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can permanently delete the store.
     */
    public function forceDelete(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can suspend the store.
     */
    public function suspend(User $user, Store $store): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin', 'moderator']);
    }

    /**
     * Determine if the user can activate the store.
     */
    public function activate(User $user, Store $store): bool
    {
        // Owner can activate their own store
        if ($user->id === $store->user_id) {
            return true;
        }

        // Admins can activate any store
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can manage store subscriptions.
     */
    public function manageSubscription(User $user, Store $store): bool
    {
        return $user->id === $store->user_id;
    }

    /**
     * Determine if the user can view store statistics.
     */
    public function viewStatistics(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can manage store orders.
     */
    public function manageOrders(User $user, Store $store): bool
    {
        // Owner can manage orders
        if ($user->id === $store->user_id) {
            return true;
        }

        // Admins can manage any store orders
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can view store analytics.
     */
    public function viewAnalytics(User $user, Store $store): bool
    {
        // Owner can view analytics
        if ($user->id === $store->user_id) {
            return true;
        }

        // Finance and admin roles can view analytics
        return $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }
}
