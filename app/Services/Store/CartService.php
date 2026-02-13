<?php

namespace App\Services\Store;

use App\Modules\Store\Models\Product as StoreProduct;
use Illuminate\Support\Collection;

/**
 * Cart Service
 *
 * Handles shopping cart functionality for the store module
 */
class CartService
{
    protected $cartKey = 'store_cart';

    /**
     * Add item to cart
     */
    public function addItem($product, int $quantity = 1, array $options = []): array
    {
        // Accept StoreProduct models (Module)
        if (!($product instanceof StoreProduct)
            && !($product instanceof \App\Modules\Store\Models\Product)) {
            throw new \InvalidArgumentException('Product must be an instance of StoreProduct or Product');
        }

        // Check if product is out of stock (status-based check)
        if ($product->status === 'out_of_stock') {
            throw new \Exception('Product is out of stock');
        }

        // Validate stock quantity
        if ($product->track_inventory && $product->inventory_quantity < $quantity) {
            throw new \Exception('Insufficient stock available');
        }

        $cart = $this->getCart();
        $itemId = $this->generateItemId($product, $options);

        if (isset($cart['items'][$itemId])) {
            $newQuantity = $cart['items'][$itemId]['quantity'] + $quantity;

            // Check total quantity doesn't exceed stock
            if ($product->track_inventory && $product->inventory_quantity < $newQuantity) {
                throw new \Exception('Quantity exceeds available stock');
            }

            $cart['items'][$itemId]['quantity'] = $newQuantity;
        } else {
            $cart['items'][$itemId] = [
                'product_id' => $product->id,
                'store_id' => $product->store_id ?? null,
                'quantity' => $quantity,
                'price' => (float) $product->price_ugx,
                'options' => $options,
                'added_at' => now()->toIso8601String(),
            ];
        }

        $this->saveCart($cart);

        return $this->getCart();
    }

    /**
     * Get cart contents
     */
    public function getCart(): array
    {
        $cart = session($this->cartKey, ['items' => [], 'totals' => []]);

        // Ensure items key exists
        if (!isset($cart['items'])) {
            $cart['items'] = [];
        }

        // Calculate totals
        $subtotal = 0.0;
        foreach ($cart['items'] as $item) {
            $subtotal += ((float) $item['price']) * ((int) $item['quantity']);
        }

        $cart['totals'] = [
            'subtotal' => (float) $subtotal,
            'tax' => 0.0,
            'shipping' => 0.0,
            'discount' => 0.0,
            'total' => (float) $subtotal,
        ];

        return $cart;
    }

    /**
     * Get cart for payment (money)
     */
    public function getCartTotal(string $paymentMethod = 'money'): array
    {
        $cart = $this->getCart();

        if ($paymentMethod === 'credit') {
            // Credit payment - convert UGX to credits (1 credit = 1 UGX for simplicity)
            return [
                'total_credits' => (int) $cart['totals']['total'],
                'total_ugx' => 0,
            ];
        }

        return [
            'total_ugx' => $cart['totals']['total'],
            'total_credits' => 0,
        ];
    }

    /**
     * Get cart items with product details
     */
    public function getCartWithProducts(): Collection
    {
        $cart = $this->getCart();
        $items = $cart['items'] ?? [];
        $productIds = array_column($items, 'product_id');

        $products = StoreProduct::whereIn('id', $productIds)
            ->with('store')
            ->get()
            ->keyBy('id');

        return collect($items)->map(function ($item, $itemId) use ($products) {
            $product = $products->get($item['product_id']);

            return [
                'id' => $itemId,
                'product' => $product,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'total' => $item['price'] * $item['quantity'],
                'options' => $item['options'],
                'added_at' => $item['added_at'],
            ];
        });
    }

    /**
     * Get cart total
     */
    public function getTotal(): float
    {
        $cart = $this->getCart();
        return $cart['totals']['total'] ?? 0;
    }

    /**
     * Get cart item count
     */
    public function getItemCount(): int
    {
        $cart = $this->getCart();
        $items = $cart['items'] ?? [];

        return array_sum(array_column($items, 'quantity'));
    }

    /**
     * Clear cart
     */
    public function clear(): void
    {
        session()->forget($this->cartKey);
    }

    /**
     * Check if cart is empty
     */
    public function isEmpty(): bool
    {
        $cart = $this->getCart();
        return empty($cart['items'] ?? []);
    }

    /**
     * Remove item from cart
     */
    public function removeItem(string $itemId): bool
    {
        $cart = $this->getCart();

        if (isset($cart['items'][$itemId])) {
            unset($cart['items'][$itemId]);
            $this->saveCart($cart);
            return true;
        }

        return false;
    }

    /**
     * Update item quantity
     */
    public function updateQuantity(string $itemId, int $quantity): bool
    {
        $cart = $this->getCart();

        if (isset($cart['items'][$itemId])) {
            if ($quantity <= 0) {
                unset($cart['items'][$itemId]);
            } else {
                $cart['items'][$itemId]['quantity'] = $quantity;
            }

            $this->saveCart($cart);
            return true;
        }

        return false;
    }

    /**
     * Generate unique item ID
     */
    private function generateItemId($product, array $options = []): string
    {
        $optionsHash = md5(serialize($options));
        return "product_{$product->id}_{$optionsHash}";
    }

    /**
     * Save cart to session
     */
    private function saveCart(array $cart): void
    {
        session([$this->cartKey => $cart]);
    }
}
