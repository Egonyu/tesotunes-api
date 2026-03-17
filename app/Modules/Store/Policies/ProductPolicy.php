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
        if ($product->store->canBeManagedBy($user)) {
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
        return $user->stores()
            ->whereIn('status', ['active', 'draft', 'pending'])
            ->get()
            ->contains(fn ($store) => $store->canAddProducts());
    }

    /**
     * Determine if the user can update the product.
     */
    public function update(User $user, Product $product): bool
    {
        // Store owner can update
        if ($product->store->canBeManagedBy($user)) {
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
        if ($product->store->canBeManagedBy($user)) {
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
        return $product->store->canBeManagedBy($user) ||
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
        return $product->store->canBeManagedBy($user);
    }

    /**
     * Determine if the user can feature the product.
     */
    public function feature(User $user, Product $product): bool
    {
        // Store owner can feature if premium
        if ($product->store->canBeManagedBy($user) && $product->store->is_premium) {
            return true;
        }

        // Admins can feature any product
        return $user->hasAnyRole(['admin', 'super_admin']);
    }
}
