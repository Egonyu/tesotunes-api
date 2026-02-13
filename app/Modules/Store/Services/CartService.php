<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Models\Product;
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
    public function addItem(Product $product, int $quantity = 1, array $options = []): array
    {
        $cart = $this->getCart();
        $itemId = $this->generateItemId($product, $options);

        if (isset($cart[$itemId])) {
            $cart[$itemId]['quantity'] += $quantity;
        } else {
            $cart[$itemId] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $product->price_ugx,
                'options' => $options,
                'added_at' => now()->toIso8601String(),
            ];
        }

        $this->saveCart($cart);

        return $cart[$itemId];
    }

    /**
     * Remove item from cart
     */
    public function removeItem(string $itemId): bool
    {
        $cart = $this->getCart();

        if (isset($cart[$itemId])) {
            unset($cart[$itemId]);
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

        if (isset($cart[$itemId])) {
            if ($quantity <= 0) {
                return $this->removeItem($itemId);
            }

            $cart[$itemId]['quantity'] = $quantity;
            $this->saveCart($cart);
            return true;
        }

        return false;
    }

    /**
     * Get cart contents
     */
    public function getCart($user = null): array
    {
        $cart = session($this->cartKey, []);
        
        // Return with count for backward compatibility with tests
        if ($user !== null) {
            return [
                'items' => $cart,
                'count' => $this->getItemCount()
            ];
        }
        
        return $cart;
    }

    /**
     * Get cart items with product details
     */
    public function getCartWithProducts(): Collection
    {
        $cart = $this->getCart();
        $productIds = array_column($cart, 'product_id');

        $products = Product::whereIn('id', $productIds)
            ->with('store')
            ->get()
            ->keyBy('id');

        return collect($cart)->map(function ($item, $itemId) use ($products) {
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

        return array_reduce($cart, function ($total, $item) {
            return $total + ($item['price'] * $item['quantity']);
        }, 0);
    }

    /**
     * Get cart item count
     */
    public function getItemCount(): int
    {
        $cart = $this->getCart();

        return array_sum(array_column($cart, 'quantity'));
    }

    /**
     * Clear cart
     */
    public function clear(): void
    {
        session()->forget($this->cartKey);
    }

    /**
     * Backwards-compatible alias for clearing the cart used in some tests
     */
    public function clearCart(): void
    {
        $this->clear();
    }

    /**
     * Check if cart is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->getCart());
    }

    /**
     * Generate unique item ID
     */
    private function generateItemId(Product $product, array $options = []): string
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
