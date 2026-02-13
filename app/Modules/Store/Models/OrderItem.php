<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderItem Model
 * 
 * Individual line items in an order
 * Stores product snapshot and currency breakdown
 */
class OrderItem extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\OrderItemFactory::new();
    }

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_snapshot',
        'product_name',
        'product_description',
        'product_image',
        'product_type',
        'product_sku',
        'product_variant',
        'quantity',
        'unit_price',
        'subtotal',
        'tax_amount',
        'total_amount',
        'fulfillment_status',
        'download_url',
        'download_count',
        'download_expires_at',
        // Verification fields (for promotional products)
        'verification_status',
        'verification_url',
        'verification_proof',
        'verification_notes',
        'verification_submitted_at',
        'verification_expires_at',
        'verified_at',
        'verified_by',
        'rejection_reason',
        'dispute_reason',
    ];

    protected $casts = [
        'product_snapshot' => 'array',
        'product_variant' => 'array',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'download_count' => 'integer',
        'download_expires_at' => 'datetime',
        // Verification casts
        'verification_submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'verification_expires_at' => 'datetime',
    ];

    // Fulfillment status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_CANCELLED = 'cancelled';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getIsFulfilledAttribute(): bool
    {
        return $this->fulfillment_status === self::STATUS_FULFILLED;
    }

    public function getIsDigitalAttribute(): bool
    {
        return $this->product_type === Product::TYPE_DIGITAL;
    }

    public function getCanDownloadAttribute(): bool
    {
        return $this->is_digital 
            && $this->download_url 
            && (!$this->download_expires_at || $this->download_expires_at->isFuture());
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    public function markAsFulfilled(): bool
    {
        return $this->update([
            'fulfillment_status' => self::STATUS_FULFILLED,
            'fulfilled_at' => now(),
        ]);
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('download_count');
    }

    /**
     * Create order item from product with full snapshot
     */
    public static function createFromProduct(Product $product, int $quantity, array $options = []): array
    {
        $unitPrice = $product->price_ugx;
        $subtotal = $unitPrice * $quantity;
        
        return [
            'product_id' => $product->id,
            'product_snapshot' => $product->toArray(),
            'product_name' => $product->name,
            'product_description' => $product->description,
            'product_image' => $product->featured_image,
            'product_type' => $product->product_type,
            'product_sku' => $product->sku,
            'product_variant' => $options,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total_amount' => $subtotal,
        ];
    }

    /**
     * Get product details from snapshot if product is deleted
     */
    public function getProductDetails(): array
    {
        // If product exists, return fresh data
        if ($this->product) {
            return $this->product->toArray();
        }

        // Otherwise return snapshot
        return $this->product_snapshot ?? [
            'name' => $this->product_name,
            'description' => $this->product_description,
            'image' => $this->product_image,
            'type' => $this->product_type,
            'sku' => $this->product_sku,
        ];
    }
}
