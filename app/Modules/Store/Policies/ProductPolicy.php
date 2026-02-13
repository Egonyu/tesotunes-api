<?php

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Product;

class ProductPolicy
{
    /**
     * Determine if the user can view any products.
     */
    public function viewAny(User $user): bool
    {
        return true; // Public browsing allowed
    }

    /**
     * Determine if the user can view the product.
     */
    public function view(User $user, Product $product): bool
    {
        // Store owner can view
        if ($user->id === $product->store->user_id) {
            return true;
        }

        // Admins can view
        if ($user->hasAnyRole(['admin', 'super_admin', 'moderator'])) {
            return true;
        }

        // Public can view active products
        return $product->status === Product::STATUS_ACTIVE;
    }

    /**
     * Determine if the user can create products.
     */
    public function create(User $user): bool
    {
        $store = $user->store;

        if (!$store) {
            return false;
        }

        // Check if store is active
        if ($store->status !== 'active' && $store->status !== 'draft') {
            return false;
        }

        // Check product limit
        return $store->canAddProducts();
    }

    /**
     * Determine if the user can update the product.
     */
    public function update(User $user, Product $product): bool
    {
        // Store owner can update
        if ($user->id === $product->store->user_id) {
            return true;
        }

        // Admins can update
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can delete the product.
     */
    public function delete(User $user, Product $product): bool
    {
        // Store owner can delete
        if ($user->id === $product->store->user_id) {
            return true;
        }

        // Admins can delete
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can restore the product.
     */
    public function restore(User $user, Product $product): bool
    {
        return $user->id === $product->store->user_id || 
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can permanently delete the product.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can manage product inventory.
     */
    public function manageInventory(User $user, Product $product): bool
    {
        return $user->id === $product->store->user_id;
    }

    /**
     * Determine if the user can feature the product.
     */
    public function feature(User $user, Product $product): bool
    {
        // Store owner can feature if premium
        if ($user->id === $product->store->user_id && $product->store->is_premium) {
            return true;
        }

        // Admins can feature any product
        return $user->hasAnyRole(['admin', 'super_admin']);
    }
}
