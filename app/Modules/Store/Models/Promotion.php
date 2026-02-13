<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promotion Model
 *
 * NOTE: Store-specific promotions use the products table with TYPE_PROMOTION.
 * This model maps to promotion_campaigns for platform-wide promotions.
 * For store-specific promotions, use Product::scopePromotions() instead.
 * 
 * @see \App\Models\PromotionCampaign for platform-wide campaigns
 * @see \App\Modules\Store\Models\Product::scopePromotions() for store promotions
 */
class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     * Maps to promotion_campaigns table for platform-wide promotions.
     */
    protected $table = 'promotion_campaigns';

    protected static function newFactory()
    {
        return \Database\Factories\Modules\Store\Models\PromotionFactory::new();
    }

    protected $fillable = [
        'name',
        'slug',
        'description',
        'created_by_id',
        'campaign_type',
        'discount_type',
        'discount_value',
        'max_discount_ugx',
        'min_purchase_ugx',
        'min_purchase_credits',
        'applies_to',
        'applicable_ids',
        'usage_limit_total',
        'usage_limit_per_user',
        'usage_count',
        'starts_at',
        'ends_at',
        'status',
        'is_active',
        'priority',
        'can_stack',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount_ugx' => 'decimal:2',
        'min_purchase_ugx' => 'decimal:2',
        'min_purchase_credits' => 'decimal:2',
        'usage_limit_total' => 'integer',
        'usage_limit_per_user' => 'integer',
        'usage_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'applicable_ids' => 'array',
        'is_active' => 'boolean',
        'can_stack' => 'boolean',
    ];

    // Campaign type constants
    const TYPE_DISCOUNT = 'discount';
    const TYPE_FREE_SHIPPING = 'free_shipping';
    const TYPE_BUY_X_GET_Y = 'buy_x_get_y';
    const TYPE_BUNDLE = 'bundle';
    const TYPE_LOYALTY = 'loyalty';
    const TYPE_REFERRAL = 'referral';
    const TYPE_SEASONAL = 'seasonal';

    // Discount type constants
    const DISCOUNT_PERCENTAGE = 'percentage';
    const DISCOUNT_FIXED = 'fixed_amount';
    const DISCOUNT_FREE_SHIPPING = 'free_shipping';

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_EXPIRED = 'expired';
    const STATUS_COMPLETED = 'completed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who created this promotion
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_id');
    }

    /**
     * Alias for creator relationship
     */
    public function createdBy(): BelongsTo
    {
        return $this->creator();
    }

    /**
     * Get promo codes for this campaign
     */
    public function promoCodes(): HasMany
    {
        return $this->hasMany(\App\Models\PromoCode::class, 'campaign_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: Active promotions only
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Business Logic Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if promotion is currently valid
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->is_active
            && $this->starts_at <= now()
            && $this->ends_at >= now()
            && ($this->usage_limit_total === null || $this->usage_count < $this->usage_limit_total);
    }

    /**
     * Calculate discount amount
     */
    public function calculateDiscount(float $amount): float
    {
        if (!$this->isValid()) {
            return 0;
        }

        if ($this->min_purchase_ugx && $amount < $this->min_purchase_ugx) {
            return 0;
        }

        $discount = match($this->discount_type) {
            self::DISCOUNT_PERCENTAGE => $amount * ($this->discount_value / 100),
            self::DISCOUNT_FIXED => $this->discount_value,
            default => 0,
        };

        if ($this->max_discount_ugx && $discount > $this->max_discount_ugx) {
            $discount = $this->max_discount_ugx;
        }

        return $discount;
    }
}