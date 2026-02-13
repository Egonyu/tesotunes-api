<?php

namespace App\Modules\Store\Services;

use App\Mail\Store\OrderCreatedMail;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\Product;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * Store Notification Service
 * 
 * Handles all notification logic for the Store module
 * Supports: In-app, Email, SMS (future)
 */
class NotificationService
{
    /**
     * Notify buyer when order is created
     */
    public function notifyOrderCreated(Order $order): void
    {
        $user = $order->user;
        
        // In-app notification
        Notification::createForUser(
            $user,
            'store_order_created',
            'Order Confirmed',
            "Your order #{$order->order_number} has been confirmed for UGX " . number_format($order->total_ugx),
            [
                'order_number' => $order->order_number,
                'total_ugx' => $order->total_ugx,
                'items_count' => $order->items->count(),
            ],
            route('store.orders.show', $order->order_number)
        );

        // Email notification
        try {
            Mail::to($user->email)->queue(new OrderCreatedMail($order));
        } catch (\Exception $e) {
            Log::error('Failed to send order created email', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify seller when new order is received
     */
    public function notifySellerNewOrder(Order $order): void
    {
        $seller = $order->store->owner;
        
        Notification::createForUser(
            $seller,
            'store_new_order',
            'New Order Received',
            "New order #{$order->order_number} from {$order->user->name} - UGX " . number_format($order->total_ugx),
            [
                'order_number' => $order->order_number,
                'buyer_name' => $order->user->name,
                'total_ugx' => $order->total_ugx,
            ],
            route('store.seller.orders.show', $order->order_number)
        );
    }

    /**
     * Notify buyer when order status changes
     */
    public function notifyOrderStatusChanged(Order $order, string $oldStatus, string $newStatus): void
    {
        $user = $order->user;
        
        $statusMessages = [
            'processing' => 'Your order is being processed',
            'shipped' => 'Your order has been shipped',
            'delivered' => 'Your order has been delivered',
            'cancelled' => 'Your order has been cancelled',
        ];

        $message = $statusMessages[$newStatus] ?? "Order status updated to {$newStatus}";
        
        Notification::createForUser(
            $user,
            'store_order_status',
            'Order Update',
            "{$message} - Order #{$order->order_number}",
            [
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
            route('store.orders.show', $order->order_number)
        );
    }

    /**
     * Notify buyer when payment is successful
     */
    public function notifyPaymentSuccessful(Order $order): void
    {
        $user = $order->user;
        
        Notification::createForUser(
            $user,
            'store_payment_success',
            'Payment Successful',
            "Payment confirmed for order #{$order->order_number}",
            [
                'order_number' => $order->order_number,
                'payment_method' => $order->payment_method,
                'amount' => $order->total_ugx,
            ],
            route('store.orders.show', $order->order_number)
        );
    }

    /**
     * Notify buyer when payment fails
     */
    public function notifyPaymentFailed(Order $order, string $reason = null): void
    {
        $user = $order->user;
        
        Notification::createForUser(
            $user,
            'store_payment_failed',
            'Payment Failed',
            "Payment for order #{$order->order_number} could not be processed. " . ($reason ?? 'Please try again.'),
            [
                'order_number' => $order->order_number,
                'reason' => $reason,
            ],
            route('store.orders.show', $order->order_number)
        );
    }

    /**
     * Notify seller when product review is submitted
     */
    public function notifyProductReviewed(Product $product, User $reviewer, int $rating): void
    {
        $seller = $product->store->owner;
        
        $stars = str_repeat('⭐', $rating);
        
        Notification::createForUser(
            $seller,
            'store_new_review',
            'New Product Review',
            "{$reviewer->name} reviewed \"{$product->name}\" - {$stars}",
            [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'rating' => $rating,
                'reviewer' => $reviewer->name,
            ],
            route('store.seller.products.reviews', $product->id)
        );
    }

    /**
     * Notify buyer when low stock on wishlisted item
     */
    public function notifyLowStock(Product $product, User $user): void
    {
        Notification::createForUser(
            $user,
            'store_low_stock',
            'Low Stock Alert',
            "\"{$product->name}\" is running low. Only {$product->inventory_quantity} left!",
            [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'inventory' => $product->inventory_quantity,
            ],
            route('store.products.show', $product->slug)
        );
    }

    /**
     * Notify followers when store has new product
     */
    public function notifyNewProduct(Store $store, Product $product): void
    {
        // Get store followers (if follower system exists)
        $followerIds = $store->followers()->pluck('user_id')->toArray();
        
        if (empty($followerIds)) {
            return;
        }

        Notification::createBatchForUsers(
            $followerIds,
            'store_new_product',
            'New Product Available',
            "{$store->name} added a new product: \"{$product->name}\"",
            [
                'store_id' => $store->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
            ],
            route('store.products.show', $product->slug)
        );
    }

    /**
     * Notify about promotion/discount
     */
    public function notifyPromotion(Store $store, string $title, string $message, array $userIds = []): void
    {
        if (empty($userIds)) {
            // Send to all followers
            $userIds = $store->followers()->pluck('user_id')->toArray();
        }

        if (empty($userIds)) {
            return;
        }

        Notification::createBatchForUsers(
            $userIds,
            'store_promotion',
            $title,
            $message,
            [
                'store_id' => $store->id,
                'store_name' => $store->name,
            ],
            route('store.shop.store', $store->slug)
        );
    }

    /**
     * Send SMS notification (via SMS service)
     */
    protected function sendSMS(string $phoneNumber, string $message): bool
    {
        try {
            // TODO: Integrate with SMS provider (Africa's Talking, Twilio)
            // For now, just log
            Log::info("SMS to {$phoneNumber}: {$message}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send SMS: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify via SMS for important order updates
     */
    public function notifyOrderViaSMS(Order $order, string $status): void
    {
        $phone = $order->shipping_address['phone'] ?? $order->user->phone;
        
        if (!$phone) {
            return;
        }

        $messages = [
            'confirmed' => "Your order #{$order->order_number} is confirmed. Track at: " . route('store.orders.show', $order->order_number),
            'shipped' => "Your order #{$order->order_number} has been shipped!",
            'delivered' => "Your order #{$order->order_number} has been delivered. Enjoy!",
        ];

        $message = $messages[$status] ?? null;
        
        if ($message) {
            $this->sendSMS($phone, $message);
        }
    }

    /**
     * Get notification preferences for user
     */
    public function getUserPreferences(User $user): array
    {
        return [
            'in_app' => true, // Always enabled
            'email' => $user->settings->email_notifications ?? true,
            'sms' => $user->settings->sms_notifications ?? false,
            'push' => $user->settings->push_notifications ?? false,
        ];
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(User $user, array $preferences): bool
    {
        return $user->settings()->update([
            'email_notifications' => $preferences['email'] ?? true,
            'sms_notifications' => $preferences['sms'] ?? false,
            'push_notifications' => $preferences['push'] ?? false,
        ]);
    }
}
