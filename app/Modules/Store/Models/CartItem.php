<?php

namespace App\Modules\Store\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return CartItemFactory::new();
    }
    protected $fillable = [
        'cart_id',
        'product_id',
        'variant_id',
        'quantity',
        'payment_preference',
        'custom_options',
        'notes',
    ];

    protected $casts = [
        'custom_options' => 'array',
        'quantity' => 'integer',
    ];

    /**
     * Get the cart this item belongs to
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Get the product
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the product variant (if applicable)
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Get the price in UGX (from product or variant)
     */
    public function getPriceUgx(): float
    {
        if ($this->variant_id && $this->variant) {
            return $this->variant->getFinalPrice();
        }
        
        return $this->product->pricing?->price_ugx ?? 0;
    }

    /**
     * Get the price in credits (from product)
     */
    public function getPriceCredits(): int
    {
        return $this->product->pricing?->price_credits ?? 0;
    }

    /**
     * Calculate subtotal in UGX
     */
    public function getSubtotalUgx(): float
    {
        return $this->getPriceUgx() * $this->quantity;
    }

    /**
     * Calculate subtotal in credits
     */
    public function getSubtotalCredits(): int
    {
        return $this->getPriceCredits() * $this->quantity;
    }

    /**
     * Backward compatibility accessors
     */
    public function getSubtotalUgxAttribute(): float
    {
        return $this->getSubtotalUgx();
    }

    public function getSubtotalCreditsAttribute(): int
    {
        return $this->getSubtotalCredits();
    }

    /**
     * Check if product/variant is available
     */
    public function isAvailable(): bool
    {
        if (!$this->product) {
            return false;
        }

        // Check if product is still active
        if ($this->product->status !== 'active') {
            return false;
        }

        // If variant selected, check variant stock
        if ($this->variant_id && $this->variant) {
            return $this->variant->isInStock() && 
                   $this->variant->stock_quantity >= $this->quantity;
        }

        // Check product stock for physical products
        if ($this->product->product_type === 'physical') {
            $inventory = $this->product->inventory;
            if (!$inventory) {
                return false;
            }
            return $inventory->stock_quantity >= $this->quantity;
        }

        return true;
    }

    /**
     * Check if more items can be added
     */
    public function canIncrementQuantity(int $amount = 1): bool
    {
        $newQuantity = $this->quantity + $amount;

        // Check variant stock
        if ($this->variant_id && $this->variant) {
            return $this->variant->stock_quantity >= $newQuantity;
        }

        // Check product stock
        if ($this->product->product_type === 'physical') {
            $inventory = $this->product->inventory;
            if (!$inventory) {
                return false;
            }
            return $inventory->stock_quantity >= $newQuantity;
        }

        return true;
    }

    /**
     * Update quantity
     */
    public function updateQuantity(int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        // Check variant stock
        if ($this->variant_id && $this->variant) {
            if ($quantity > $this->variant->stock_quantity) {
                return false;
            }
        }
        // Check product stock
        elseif ($this->product->product_type === 'physical') {
            $inventory = $this->product->inventory;
            if (!$inventory || $quantity > $inventory->stock_quantity) {
                return false;
            }
        }

        $this->update(['quantity' => $quantity]);
        $this->cart->markAsActive();

        return true;
    }

    /**
     * Get available stock
     */
    public function getAvailableStock(): int
    {
        if ($this->variant_id && $this->variant) {
            return $this->variant->stock_quantity;
        }

        if ($this->product->product_type === 'physical') {
            return $this->product->inventory?->stock_quantity ?? 0;
        }

        return PHP_INT_MAX; // Unlimited for digital/service products
    }
}
