<?php

namespace App\Policies\Store;

use App\Models\User;
use App\Modules\Store\Models\Store;

class StorePolicy
{
    /**
     * Determine if the user can view any stores.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the store.
     */
    public function view(?User $user, Store $store): bool
    {
        return $store->status === 'active' || 
               ($user && ($user->id === $store->user_id || $user->hasAnyRole(['admin', 'super_admin'])));
    }

    /**
     * Determine if the user can create stores.
     */
    public function create(User $user): bool
    {
        // Check if store module is enabled
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

        // Check if artists only mode is enabled
        if (config('modules.store.artists_only', false)) {
            return $user->hasRole('artist');
        }

        return true;
    }

    /**
     * Determine if the user can update the store.
     */
    public function update(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can delete the store.
     */
    public function delete(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can manage store orders.
     */
    public function manageOrders(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can view store analytics.
     */
    public function viewAnalytics(User $user, Store $store): bool
    {
        return $user->id === $store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }
}
