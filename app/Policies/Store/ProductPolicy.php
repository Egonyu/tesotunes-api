<?php

namespace App\Policies\Store;

use App\Modules\Store\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
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
    public function view(?User $user, Product $product): bool
    {
        // Public can view active products
        if ($product->status === 'active') {
            return true;
        }

        // Owner and admins can view any product
        return $user && (
            $product->store->owner_id === $user->id ||
            $user->hasAnyRole(['admin', 'super_admin'])
        );
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Must have an active store to create products
        return $user->stores()->where('status', 'active')->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        return $product->store->owner_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        return $product->store->owner_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Product $product): bool
    {
        return $product->store->owner_id === $user->id ||
               $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']);
    }
}
