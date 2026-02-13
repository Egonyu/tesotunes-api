<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\{Order, OrderItem, Product, Store};
use App\Events\Store\OrderCreated;
use App\Models\{User, Payment};
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * OrderService
 * 
 * Handles all business logic for order management
 */
class OrderService
{
    public function __construct(
        protected ProductService $productService,
        protected PaymentService $paymentService
    ) {}

    /**
     * Create a new order
     */
    public function create(User $buyer, Store $store, array $items, array $data = []): Order
    {
        return DB::transaction(function () use ($buyer, $store, $items, $data) {
            // Calculate totals
            $totals = $this->calculateTotals($items, array_merge($data, ['store_id' => $store->id]));
            
            // Create order
            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'store_id' => $store->id,
                'user_id' => $buyer->id,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_PENDING,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_provider' => $data['payment_provider'] ?? null,
                // Legacy single-currency fields (for backward compatibility)
                'subtotal' => $totals['subtotal_ugx'] + ($totals['subtotal_credits'] ?? 0),
                'tax_amount' => $totals['tax_ugx'] + ($totals['tax_credits'] ?? 0),
                'shipping_amount' => $totals['shipping_ugx'] + ($totals['shipping_credits'] ?? 0),
                'discount_amount' => $totals['discount_ugx'] + ($totals['discount_credits'] ?? 0),
                'total_amount' => $totals['total_ugx'] + ($totals['total_credits'] ?? 0),
                'credit_amount' => $totals['total_credits'] ?? 0,
                // Dual-currency breakdown
                'subtotal_ugx' => $totals['subtotal_ugx'],
                'subtotal_credits' => $totals['subtotal_credits'] ?? 0,
                'tax_ugx' => $totals['tax_ugx'],
                'tax_credits' => $totals['tax_credits'] ?? 0,
                'shipping_cost_ugx' => $totals['shipping_ugx'],
                'shipping_cost_credits' => $totals['shipping_credits'] ?? 0,
                'discount_ugx' => $totals['discount_ugx'],
                'discount_credits' => $totals['discount_credits'] ?? 0,
                'platform_fee_ugx' => $totals['platform_fee_ugx'],
                'platform_fee_credits' => $totals['platform_fee_credits'] ?? 0,
                'total_ugx' => $totals['total_ugx'],
                'total_credits' => $totals['total_credits'] ?? 0,
                // Other fields
                'shipping_address' => $data['shipping_address'] ?? null,
                'customer_notes' => $data['notes'] ?? null,
            ]);
            
            // Create order items
            foreach ($items as $item) {
                $product = Product::findOrFail($item['product_id']);
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_description' => $product->short_description,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price_ugx,
                    'subtotal' => $product->price_ugx * $item['quantity'],
                    'tax_amount' => 0,
                    'total_amount' => $product->price_ugx * $item['quantity'],
                ]);
                
                // Reserve inventory
                $this->productService->updateInventory($product, $item['quantity'], 'subtract');
            }
            
            // Handle mobile money payment
            if (($data['payment_method'] ?? '') === 'mobile_money') {
                $mobileMoneyService = app(\App\Services\Payment\MobileMoneyService::class);
                $paymentResponse = $mobileMoneyService->processPayment([
                    'amount' => $order->total_ugx,
                    'payment_method' => $data['payment_provider'] ?? 'mtn',
                    'phone_number' => $data['phone_number'] ?? null,
                ]);
                
                if (!empty($paymentResponse['transaction_id'])) {
                    $order->update(['transaction_id' => $paymentResponse['transaction_id']]);
                }
            }
            
            // Handle credit payment if specified
            $isCreditPayment = in_array($data['payment_method'] ?? '', ['credits', 'credit']) || ($data['use_credits'] ?? false);
            if ($isCreditPayment && $order->total_credits > 0) {
                // Deduct credits from user
                $buyer->decrement('credits', $order->total_credits);
                
                // Mark order as paid since credits were used
                $order->update([
                    'payment_status' => Order::PAYMENT_PAID,
                    'paid_at' => now(),
                    'paid_credits' => $order->total_credits,
                ]);
            }
            
            // Clear cart after successful order
            session()->forget('store_cart');
            
            // Dispatch order created event
            event(new OrderCreated($order));
            
            return $order;
        });
    }

    /**
     * Calculate order totals with dual-currency support
     */
    public function calculateTotals(array $items, array $data = []): array
    {
        $subtotalUgx = 0;
        $subtotalCredits = 0;
        
        foreach ($items as $item) {
            $product = Product::findOrFail($item['product_id']);
            $quantity = $item['quantity'];
            
            $subtotalUgx += $product->price_ugx * $quantity;
            $subtotalCredits += ($product->price_credits ?? 0) * $quantity;
        }
        
        // Calculate shipping (simplified - can be enhanced)
        $shippingUgx = $data['shipping_cost_ugx'] ?? $data['shipping_cost'] ?? 5000;
        $shippingCredits = $data['shipping_cost_credits'] ?? 0;
        
        // Calculate tax (if applicable)
        $taxUgx = 0;
        $taxCredits = 0;
        
        // Calculate discount (if promo code applied)
        $discountUgx = $data['discount_ugx'] ?? 0;
        $discountCredits = $data['discount_credits'] ?? 0;
        
        // Calculate platform fee
        $store = Store::find($data['store_id']);
        $platformFeeUgx = $store ? $store->calculatePlatformFee($subtotalUgx) : 0;
        $platformFeeCredits = 0; // Platform fee typically only on UGX
        
        // Calculate totals
        $totalUgx = $subtotalUgx + $shippingUgx + $taxUgx - $discountUgx;
        $totalCredits = $subtotalCredits + $shippingCredits + $taxCredits - $discountCredits;
        
        return [
            // Dual-currency breakdown
            'subtotal_ugx' => $subtotalUgx,
            'subtotal_credits' => $subtotalCredits,
            'tax_ugx' => $taxUgx,
            'tax_credits' => $taxCredits,
            'shipping_ugx' => $shippingUgx,
            'shipping_credits' => $shippingCredits,
            'discount_ugx' => $discountUgx,
            'discount_credits' => $discountCredits,
            'platform_fee_ugx' => $platformFeeUgx,
            'platform_fee_credits' => $platformFeeCredits,
            'total_ugx' => $totalUgx,
            'total_credits' => $totalCredits,
        ];
    }

    /**
     * Process payment for order
     */
    public function processPayment(Order $order, array $paymentData): Payment
    {
        return $this->paymentService->processOrderPayment($order, $paymentData);
    }

    /**
     * Mark order as paid
     */
    public function markAsPaid(Order $order, float $paidAmount, int $paidCredits = 0): bool
    {
        return DB::transaction(function () use ($order, $paidAmount, $paidCredits) {
            $order->update([
                'payment_status' => Order::PAYMENT_PAID,
                'paid_at' => now(),
                'paid_credits' => $paidCredits,
            ]);
            
            return $order->markAsPaid();
        });
    }

    /**
     * Mark order as shipped
     */
    public function markAsShipped(Order $order, string $trackingNumber = null, string $provider = null): bool
    {
        return $order->markAsShipped($trackingNumber, $provider);
    }

    /**
     * Mark order as delivered
     */
    public function markAsDelivered(Order $order): bool
    {
        return $order->markAsDelivered();
    }

    /**
     * Cancel order
     */
    public function cancel(Order $order, string $reason = null): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            // Restore inventory
            foreach ($order->items as $item) {
                if ($item->product) {
                    $this->productService->updateInventory($item->product, $item->quantity, 'add');
                }
            }
            
            return $order->cancel($reason);
        });
    }

    /**
     * Create order from cart (wrapper method for controller compatibility)
     */
    public function createOrder(User $buyer, array $data): Order
    {
        // Try to get database cart first (new approach)
        $dbCart = \App\Modules\Store\Models\Cart::where('user_id', $buyer->id)
            ->where('status', 'active')
            ->with('items.product')
            ->first();
        
        if ($dbCart && !$dbCart->isEmpty()) {
            // Use database cart
            $items = $dbCart->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ];
            })->toArray();
            
            $store = $dbCart->items->first()->product->store;
            
            // Validate credits if payment method is credits
            if (in_array($data['payment_method'] ?? null, ['credit', 'credits'])) {
                $totalCreditsNeeded = $dbCart->items->sum(function ($item) {
                    return ($item->product->price_credits ?? 0) * $item->quantity;
                });
                
                if ($buyer->credits < $totalCreditsNeeded) {
                    throw new \Exception('Insufficient credits. You need ' . $totalCreditsNeeded . ' credits but only have ' . $buyer->credits . '.');
                }
            }
            
            return $this->create($buyer, $store, $items, $data);
        }
        
        // Fallback to session cart (legacy)
        $cart = session('store_cart', []);
        if (empty($cart)) {
            throw new \Exception('Cart is empty');
        }
        
        // Handle both direct array and structured cart with 'items' key
        $cartItems = isset($cart['items']) ? $cart['items'] : $cart;
        
        if (empty($cartItems)) {
            throw new \Exception('Cart is empty');
        }
        
        // Convert cart items to order items format
        $items = collect($cartItems)->map(function ($item) {
            return [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ];
        })->toArray();
        
        // For simplicity, assume all items are from the first product's store
        $firstItem = reset($cartItems);
        $firstProduct = Product::find($firstItem['product_id']);
        $store = $firstProduct->store;
        
        return $this->create($buyer, $store, $items, $data);
    }

    /**
     * Cancel order (wrapper method for controller compatibility)
     */
    public function cancelOrder(Order $order, string $reason = null): bool
    {
        return $this->cancel($order, $reason);
    }

    /**
     * Confirm order received by buyer
     */
    public function confirmOrderReceived(Order $order): bool
    {
        $order->update([
            'status' => Order::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return true;
    }

    /**
     * Get tracking information
     */
    public function getTrackingInfo(Order $order): array
    {
        return [
            'tracking_number' => $order->tracking_number,
            'shipping_provider' => $order->shipping_provider,
            'status' => $order->status,
            'shipped_at' => $order->shipped_at,
            'delivered_at' => $order->delivered_at,
        ];
    }
}
