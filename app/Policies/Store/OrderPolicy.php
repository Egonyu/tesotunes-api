<?php

namespace App\Policies\Store;

use App\Models\User;
use App\Modules\Store\Models\Order;

class OrderPolicy
{
    /**
     * Determine if the user can view any orders.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the order.
     */
    public function view(User $user, Order $order): bool
    {
        // Buyer can view their order
        if ($user->id === $order->user_id) {
            return true;
        }

        // Store owner can view orders for their store
        if ($order->store_id && $order->store && $user->id === $order->store->user_id) {
            return true;
        }

        // Admins can view all orders
        return $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }

    /**
     * Determine if the user can update the order.
     */
    public function update(User $user, Order $order): bool
    {
        // Buyers can confirm receipt
        if ($user->id === $order->user_id && 
            in_array($order->status, ['shipped', 'processing', 'delivered'])) {
            return true;
        }

        // Store owners can update order status
        if ($order->store_id && $order->relationLoaded('store') && $order->store) {
            if ($user->id === $order->store->user_id) {
                return true;
            }
        }

        // Admins can always update
        try {
            return $user->hasAnyRole(['admin', 'super_admin']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determine if the user can cancel the order.
     */
    public function cancel(User $user, Order $order): bool
    {
        // Can't cancel delivered or already cancelled orders
        if (in_array($order->status, ['delivered', 'cancelled', 'refunded'])) {
            return false;
        }

        // Buyer can cancel pending orders
        if ($user->id === $order->user_id && $order->status === 'pending') {
            return true;
        }

        // Store owner or admin can cancel
        if ($order->store_id && $order->store && $user->id === $order->store->user_id) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can refund the order.
     */
    public function refund(User $user, Order $order): bool
    {
        if ($order->store_id && $order->store && $user->id === $order->store->user_id) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }
}
