<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Order;
use App\Modules\Store\Models\OrderItem;
use App\Modules\Store\Models\Product as StoreProduct;
use App\Models\User;
use App\Models\Payment;
use App\Notifications\Store\OrderStatusNotification;
use App\Notifications\Store\RefundNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Create order from cart
     */
    public function createFromCart(User $user, array $cartItems, array $orderData): Order
    {
        return DB::transaction(function () use ($user, $cartItems, $orderData) {
            // Calculate totals
            $subtotal = collect($cartItems)->sum(function ($item) {
                return $item['price'] * $item['quantity'];
            });

            $taxAmount = $orderData['tax_amount'] ?? 0;
            $shippingAmount = $orderData['shipping_amount'] ?? 0;
            $discountAmount = $orderData['discount_amount'] ?? 0;
            $totalAmount = $subtotal + $taxAmount + $shippingAmount - $discountAmount;

            // Calculate platform commission (5%)
            $platformFee = $subtotal * 0.05;

            // Handle credit payment
            $useCredits = $orderData['use_credits'] ?? false;
            $paidCredits = 0;

            if ($useCredits && $user->credits >= $totalAmount) {
                $paidCredits = (int) $totalAmount;
                $user->decrement('credits', $paidCredits);
                $totalAmount = 0;
            }

            // Create order
            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $user->id,
                'store_id' => $cartItems[0]['store_id'] ?? $orderData['store_id'],
                'status' => 'pending',
                'payment_status' => $paidCredits > 0 ? 'paid' : 'pending',
                'fulfillment_status' => 'unfulfilled',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'platform_fee_ugx' => $platformFee,
                'currency' => 'UGX',
                'credit_amount' => $paidCredits,
                'paid_credits' => $paidCredits,
                'payment_method' => $orderData['payment_method'] ?? 'mobile_money',
                'shipping_address' => $orderData['shipping_address'] ?? null,
                'billing_address' => $orderData['billing_address'] ?? null,
                'customer_notes' => $orderData['customer_notes'] ?? null,
            ]);

            // Create order items
            foreach ($cartItems as $item) {
                $product = StoreProduct::find($item['product_id']);
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                    'tax_amount' => 0,
                    'total_amount' => $item['price'] * $item['quantity'],
                ]);

                // Reduce stock
                if ($product->track_inventory) {
                    $product->decrement('stock_quantity', $item['quantity']);
                    
                    // Mark as out of stock if quantity is 0
                    if ($product->stock_quantity <= 0) {
                        $product->update(['stock_quantity' => 0]);
                    }
                }
            }

            // Dispatch order created event
            event(new \App\Events\OrderCreated($order));

            return $order->fresh();
        });
    }

    /**
     * Generate unique order number
     */
    protected function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'ORD-' . strtoupper(Str::random(8));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    /**
     * Process order fulfillment
     */
    public function processFulfillment(Order $order, array $fulfillmentData): Order
    {
        DB::transaction(function () use ($order, $fulfillmentData) {
            $order->update([
                'status' => 'shipped',
                'fulfillment_status' => 'fulfilled',
                'tracking_number' => $fulfillmentData['tracking_number'] ?? null,
                'shipped_at' => now(),
            ]);
        });

        return $order->fresh();
    }

    /**
     * Cancel order and restore stock
     */
    public function cancelOrder(Order $order, string $reason = ''): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            // Restore stock
            foreach ($order->items as $item) {
                if ($item->product && $item->product->track_inventory) {
                    $item->product->increment('stock_quantity', $item->quantity);
                }
            }

            // Refund credits if used
            if ($order->paid_credits > 0) {
                $order->user->increment('credits', $order->paid_credits);
            }

            // Update order status
            $order->update([
                'status' => 'cancelled',
                'admin_notes' => $reason,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Update order status
     */
    public function updateOrderStatus(Order $order, string $status, ?string $notes = null): bool
    {
        return DB::transaction(function () use ($order, $status, $notes) {
            $order->update([
                'status' => $status,
                'notes' => $notes ? ($order->notes . "\n" . $notes) : $order->notes,
                'updated_at' => now(),
            ]);

            // Log status change
            Log::info("Order {$order->order_number} status changed to {$status}", [
                'order_id' => $order->id,
                'previous_status' => $order->getOriginal('status'),
                'new_status' => $status,
                'notes' => $notes,
            ]);

            // Send notification to customer
            $this->notifyCustomer($order, $status);

            return true;
        });
    }

    /**
     * Refund order
     */
    public function refundOrder(Order $order, ?float $amount = null, string $reason = ''): bool
    {
        if ($order->payment_status !== 'paid') {
            throw new \Exception('Cannot refund an order that has not been paid.');
        }

        $refundAmount = $amount ?? $order->total_amount;

        if ($refundAmount > $order->total_amount) {
            throw new \Exception('Refund amount cannot exceed order total.');
        }

        return DB::transaction(function () use ($order, $refundAmount, $reason) {
            // Update order status
            $order->update([
                'status' => 'refunded',
                'payment_status' => 'refunded',
                'refund_amount' => $refundAmount,
                'refund_reason' => $reason,
                'refunded_at' => now(),
            ]);

            // Create refund transaction
            $this->createRefundTransaction($order, $refundAmount, $reason);

            // Process refund with payment gateway
            $this->processRefundWithGateway($order, $refundAmount);

            // Restore product stock if needed
            $this->restoreProductStock($order);

            // Log refund
            Log::info("Order {$order->order_number} refunded", [
                'order_id' => $order->id,
                'amount' => $refundAmount,
                'reason' => $reason,
            ]);

            // Notify customer
            $this->notifyCustomerRefund($order, $refundAmount);

            return true;
        });
    }

    /**
     * Create refund transaction
     */
    protected function createRefundTransaction(Order $order, float $amount, string $reason): void
    {
        Payment::create([
            'user_id' => $order->user_id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'amount' => -$amount, // Negative for refund
            'payment_method' => $order->payment_method ?? 'refund',
            'status' => 'completed',
            'transaction_id' => 'REFUND-' . $order->order_number . '-' . time(),
            'description' => 'Refund: ' . $reason,
            'completed_at' => now(),
        ]);
    }

    /**
     * Process refund with payment gateway
     */
    protected function processRefundWithGateway(Order $order, float $amount): void
    {
        // TODO: Integrate with actual payment gateway (MTN, Airtel, etc.)
        // For now, this is a placeholder
        Log::info("Processing refund with payment gateway", [
            'order_id' => $order->id,
            'amount' => $amount,
            'payment_method' => $order->payment_method,
        ]);
    }

    /**
     * Restore product stock
     */
    protected function restoreProductStock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->product) {
                $item->product->increment('stock_quantity', $item->quantity);
            }
        }
    }

    /**
     * Notify customer of status change
     */
    protected function notifyCustomer(Order $order, string $status): void
    {
        // Send notification via email and database
        if ($order->user) {
            $order->user->notify(new OrderStatusNotification($order, $status));
        }
        
        Log::info("Customer notification sent for order status change", [
            'order_id' => $order->id,
            'customer_id' => $order->user_id,
            'status' => $status,
        ]);
    }

    /**
     * Notify customer of refund
     */
    protected function notifyCustomerRefund(Order $order, float $amount): void
    {
        // Send refund notification
        if ($order->user) {
            $order->user->notify(new RefundNotification($order, $amount));
        }
        
        Log::info("Customer notification sent for refund", [
            'order_id' => $order->id,
            'customer_id' => $order->user_id,
            'amount' => $amount,
        ]);
    }
}
