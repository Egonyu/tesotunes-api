<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\{User, Payment};

/**
 * Order Model
 * 
 * Represents a customer purchase
 * Supports dual currency payments (UGX + Credits)
 */
class Order extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $table = 'orders';
    
    protected static function newFactory()
    {
        return \Database\Factories\OrderFactory::new();
    }

    protected $fillable = [
        'order_number',
        'store_id',
        'user_id',
        'status',
        'payment_status',
        'fulfillment_status',
        'payment_method',
        'payment_provider',
        'transaction_id',
        // Totals (legacy single-currency columns kept for compatibility)
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'shipping_cost', // Alias for shipping_amount
        'discount_amount',
        'total_amount',
        'credit_amount',
        // Dual-currency breakdown
        'subtotal_ugx',
        'subtotal_credits',
        'tax_ugx',
        'tax_credits',
        'shipping_cost_ugx',
        'shipping_cost_credits',
        'discount_ugx',
        'discount_credits',
        'platform_fee_ugx',
        'platform_fee_credits',
        'total_ugx',
        'total_credits',
        'paid_ugx',
        'paid_credits',
        // Other fields
        'currency',
        'shipping_address',
        'billing_address',
        'shipping_method',
        'tracking_number',
        'shipping_provider',
        'customer_notes',
        'admin_notes',
        'paid_at',
        'shipped_at',
        'delivered_at',
        'completed_at',
        'refund_amount',
        'refund_reason',
        'refunded_at',
        'payment_failure_reason',
    ];

    protected $casts = [
        // Legacy single-currency columns
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'credit_amount' => 'integer',
        // Dual-currency breakdown
        'subtotal_ugx' => 'decimal:2',
        'subtotal_credits' => 'integer',
        'tax_ugx' => 'decimal:2',
        'tax_credits' => 'integer',
        'shipping_cost_ugx' => 'decimal:2',
        'shipping_cost_credits' => 'integer',
        'discount_ugx' => 'decimal:2',
        'discount_credits' => 'integer',
        'platform_fee_ugx' => 'decimal:2',
        'platform_fee_credits' => 'integer',
        'total_ugx' => 'decimal:2',
        'total_credits' => 'integer',
        'paid_ugx' => 'decimal:2',
        'paid_credits' => 'integer',
        // Other fields
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'refund_amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_FAILED = 'failed';

    // Payment status constants
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_PAID = 'paid';
    const PAYMENT_FAILED = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')->where('payable_type', self::class);
    }

    public function pricing()
    {
        return $this->hasOne(OrderPricing::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function shippingAddress()
    {
        return $this->hasOne(OrderAddress::class)->where('address_type', 'shipping');
    }

    public function billingAddress()
    {
        return $this->hasOne(OrderAddress::class)->where('address_type', 'billing');
    }

    public function fulfillment()
    {
        return $this->hasOne(OrderFulfillment::class);
    }

    // Placeholder for future promotion redemptions feature
    // public function promotionRedemptions(): HasMany
    // {
    //     return $this->hasMany(\App\Modules\Store\Models\PromotionRedemption::class);
    // }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeShipped($query)
    {
        return $query->where('status', self::STATUS_SHIPPED);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_PENDING);
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('created_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    // Backward-compatible accessors for normalized pricing fields
    public function getSubtotalUgxAttribute()
    {
        return $this->pricing?->subtotal_ugx ?? $this->attributes['subtotal_ugx'] ?? 0;
    }

    public function getTotalUgxAttribute()
    {
        return $this->pricing?->total_ugx ?? $this->attributes['total_ugx'] ?? 0;
    }

    public function getTotalCreditsAttribute()
    {
        return $this->pricing?->total_credits ?? $this->attributes['total_credits'] ?? 0;
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function getIsShippedAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_DELIVERED]);
    }

    public function getIsDeliveredAttribute(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function getIsCancelledAttribute(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getFormattedTotalAttribute(): string
    {
        $parts = [];
        
        if ($this->total_ugx > 0) {
            $parts[] = 'UGX ' . number_format($this->total_ugx, 0);
        }
        
        if ($this->total_credits > 0) {
            $parts[] = number_format($this->total_credits) . ' credits';
        }
        
        return implode(' + ', $parts) ?: 'UGX 0';
    }

    public function getSellerAmountAttribute(): float
    {
        return $this->subtotal_ugx - $this->platform_fee_ugx;
    }
    
    public function getSellerAmountCreditsAttribute(): int
    {
        return $this->subtotal_credits - ($this->platform_fee_credits ?? 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Business Logic Methods
    |--------------------------------------------------------------------------
    */

    public function markAsPaid(): bool
    {
        $result = $this->update([
            'payment_status' => self::PAYMENT_PAID,
            'paid_at' => now(),
            'status' => self::STATUS_PROCESSING,
        ]);
        
        if ($result) {
            // Dispatch event for loyalty points
            \App\Events\OrderPaid::dispatch($this);
        }
        
        return $result;
    }

    public function markAsShipped(string $trackingNumber = null, string $provider = null): bool
    {
        $updates = [
            'status' => self::STATUS_SHIPPED,
            'tracking_number' => $trackingNumber,
            'shipped_at' => now(),
        ];
        
        // Store shipping method instead of provider
        if ($provider) {
            $updates['shipping_method'] = $provider;
        }
        
        return $this->update($updates);
    }

    public function markAsDelivered(): bool
    {
        return $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    public function cancel(string $reason = null): bool
    {
        $updates = [
            'status' => self::STATUS_CANCELLED,
        ];
        
        // Store cancellation reason in admin_notes if provided
        if ($reason) {
            $updates['admin_notes'] = $reason;
        }
        
        // Refund credits if the order was paid with credits
        if ($this->payment_method === 'credit' && $this->total_credits > 0 && $this->user) {
            $this->user->increment('credits', $this->total_credits);
        }
        
        return $this->update($updates);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function canBeShipped(): bool
    {
        return $this->status === self::STATUS_PROCESSING 
            && $this->payment_status === self::PAYMENT_PAID;
    }

    public static function generateOrderNumber(): string
    {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(\Str::random(6));
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

    /**
     * Accessor for 'total' - calculates total from components
     */
    public function getTotalAttribute()
    {
        $subtotal = floatval($this->attributes['subtotal'] ?? 0);
        $shipping = floatval($this->attributes['shipping_amount'] ?? 0);
        $tax = floatval($this->attributes['tax_amount'] ?? 0);
        $discount = floatval($this->attributes['discount_amount'] ?? 0);
        
        return $subtotal + $shipping + $tax - $discount;
    }
    
    /**
     * Accessor for shipping_cost - alias for shipping_amount
     */
    public function getShippingCostAttribute()
    {
        return $this->shipping_amount;
    }
    
    /**
     * Mutator for shipping_cost - sets shipping_amount
     */
    public function setShippingCostAttribute($value)
    {
        $this->attributes['shipping_amount'] = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Boot Method
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (!$order->order_number) {
                $order->order_number = self::generateOrderNumber();
            }
        });

        // Update store statistics when order is paid
        static::updated(function ($order) {
            if ($order->isDirty('payment_status') && $order->is_paid) {
                $order->store->incrementSales(
                    $order->paid_ugx ?? $order->total_ugx ?? 0, 
                    $order->paid_credits ?? 0
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Order Status Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get progress percentage for status timeline
     */
    public function getProgressPercentage(): int
    {
        return match($this->status) {
            'pending', 'processing' => 15,
            'confirmed', 'preparing' => 40,
            'shipped', 'in_transit' => 65,
            'delivered', 'completed' => 100,
            default => 0,
        };
    }

    /**
     * Check if order is currently being processed
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'confirmed', 'preparing']);
    }

    /**
     * Check if order has been shipped
     */
    public function isShipped(): bool
    {
        return in_array($this->status, ['shipped', 'in_transit', 'out_for_delivery', 'delivered', 'completed']);
    }

    /**
     * Check if order has been delivered
     */
    public function isDelivered(): bool
    {
        return in_array($this->status, ['delivered', 'completed']);
    }

    /**
     * Get estimated delivery date
     */
    public function getEstimatedDeliveryAttribute()
    {
        if ($this->delivered_at) {
            return $this->delivered_at;
        }

        // Calculate based on shipping method
        $daysToAdd = match($this->shipping_method) {
            'express' => 2,
            'standard' => 5,
            default => 5,
        };

        return $this->created_at->addWeekdays($daysToAdd);
    }
}
