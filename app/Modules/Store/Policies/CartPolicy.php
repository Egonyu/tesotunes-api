<?php

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\ShoppingCart;

class CartPolicy
{
    /**
     * Determine if the user can view the cart.
     */
    public function view(User $user, ShoppingCart $cart): bool
    {
        return $user->id === $cart->user_id;
    }

    /**
     * Determine if the user can update the cart.
     */
    public function update(User $user, ShoppingCart $cart): bool
    {
        return $user->id === $cart->user_id;
    }

    /**
     * Determine if the user can delete the cart.
     */
    public function delete(User $user, ShoppingCart $cart): bool
    {
        return $user->id === $cart->user_id;
    }

    /**
     * Determine if the user can add items to the cart.
     */
    public function addItem(User $user, ShoppingCart $cart): bool
    {
        return $user->id === $cart->user_id;
    }

    /**
     * Determine if the user can remove items from the cart.
     */
    public function removeItem(User $user, ShoppingCart $cart): bool
    {
        return $user->id === $cart->user_id;
    }
}
