<?php

namespace App\Modules\Store\Traits;

use App\Modules\Store\Models\Store;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Trait HasStore
 * 
 * Adds store functionality to User model
 * Usage: use HasStore; in User model
 */
trait HasStore
{
    /**
     * Get the user's store
     */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    /**
     * Check if user has a store
     */
    public function hasStore(): bool
    {
        if (!config('store.enabled', false)) {
            return false;
        }

        return $this->store()->exists();
    }

    /**
     * Check if user can create a store
     */
    public function canCreateStore(): bool
    {
        // Module must be enabled
        if (!config('store.enabled', false)) {
            return false;
        }

        // Cannot have existing store
        if ($this->hasStore()) {
            return false;
        }

        // Must be verified
        if (!$this->email_verified_at) {
            return false;
        }

        // Artists can always create stores
        if ($this->hasRole('artist')) {
            return true;
        }

        // Regular users only if allowed by config
        return config('store.stores.allow_user_stores', false);
    }

    /**
     * Get store owner type
     */
    public function getStoreOwnerType(): string
    {
        if ($this->hasRole('artist')) {
            return 'artist';
        }

        return 'user';
    }

    /**
     * Check if user is a store seller (has active store)
     */
    public function isStoreSeller(): bool
    {
        return $this->hasStore() && $this->store->status === 'active';
    }

    /**
     * Get applicable transaction fee for this user's store
     */
    public function getStoreTransactionFee(): float
    {
        if (!$this->hasStore()) {
            return config('store.fees.free_tier', 7.0);
        }

        return match($this->store->subscription_tier) {
            'premium' => config('store.fees.premium_tier', 5.0),
            'business' => config('store.fees.business_tier', 3.0),
            default => config('store.fees.free_tier', 7.0),
        };
    }
}
