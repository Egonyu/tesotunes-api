<?php

namespace App\Modules\Store\Policies;

use App\Models\User;
use App\Modules\Store\Models\Order;

class OrderPolicy
{
    /**
     * Determine if the user can view any orders.
     */
    public function viewAny(User $user): bool
    {
        return true; // Can view their own orders
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

        // Seller can view orders from their store
        if ($user->id === $order->store->user_id) {
            return true;
        }

        // Admins can view all orders
        return $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }

    /**
     * Determine if the user can create orders.
     */
    public function create(User $user): bool
    {
        // Must have verified email
        if (!$user->email_verified_at) {
            return false;
        }

        // Must have items in cart
        $cart = $user->cart;
        if (!$cart || $cart->items->isEmpty()) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can update the order.
     */
    public function update(User $user, Order $order): bool
    {
        // Seller can update their store's orders
        if ($user->id === $order->store->user_id) {
            return true;
        }

        // Admins can update orders
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can delete the order.
     */
    public function delete(User $user, Order $order): bool
    {
        // Only super admins can delete orders
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can cancel the order.
     */
    public function cancel(User $user, Order $order): bool
    {
        // Buyer can cancel their own order
        if ($user->id === $order->user_id) {
            // Only if pending or processing
            return in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING]);
        }

        // Seller can cancel orders from their store
        if ($user->id === $order->store->user_id) {
            return true;
        }

        // Admins can cancel orders
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can update order status.
     */
    public function updateStatus(User $user, Order $order): bool
    {
        // Seller can update their store's order status
        if ($user->id === $order->store->user_id) {
            return true;
        }

        // Admins can update order status
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can view order tracking.
     */
    public function viewTracking(User $user, Order $order): bool
    {
        // Buyer can view their order tracking
        if ($user->id === $order->user_id) {
            return true;
        }

        // Seller can view tracking for their store's orders
        if ($user->id === $order->store->user_id) {
            return true;
        }

        // Admins can view all tracking
        return $user->hasAnyRole(['admin', 'super_admin']);
    }

    /**
     * Determine if the user can refund the order.
     */
    public function refund(User $user, Order $order): bool
    {
        // Only admins and finance can issue refunds
        return $user->hasAnyRole(['admin', 'super_admin', 'finance']);
    }
}
