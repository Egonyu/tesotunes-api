<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne, MorphTo};
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

/**
 * Store Model
 * 
 * Represents a seller's store/shop on the platform
 * Can be owned by artists or regular users (if allowed)
 */
class Store extends Model
{
    use HasFactory, SoftDeletes;
    
    protected static function newFactory()
    {
        return \Database\Factories\StoreFactory::new();
    }

    protected $fillable = [
        'user_id',
        'owner_id',
        'owner_type',
        'name',
        'slug',
        'description',
        'logo',
        'banner',
        'phone',
        'email',
        'address',
        'city',
        'country',
        'latitude',
        'longitude',
        'store_type',
        'subscription_tier',
        'subscription_expires_at',
        'status',
        'is_verified',
        'verified_at',
        'suspended_at',
        'suspended_reason',
        'settings',
        'metadata',
        'offers_local_pickup',
        'pickup_address',
    ];

    protected $guarded = [
        'total_sales_ugx',
        'total_sales_credits',
        'total_orders',
        'rating',
        'review_count',
    ];

    protected $casts = [
        'subscription_expires_at' => 'datetime',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'suspended_at' => 'datetime',
        'total_products' => 'integer',
        'total_orders' => 'integer',
        'total_revenue' => 'decimal:2',
        'total_sales_ugx' => 'decimal:2',
        'total_sales_credits' => 'integer',
        'rating' => 'decimal:2',
        'average_rating' => 'decimal:2',
        'review_count' => 'integer',
        'reviews_count' => 'integer',
        'settings' => 'array',
        'metadata' => 'array',
        'offers_local_pickup' => 'boolean',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_CLOSED = 'closed';

    // Store type constants
    const TYPE_ARTIST = 'artist';
    const TYPE_USER = 'user';

    // Subscription tier constants
    const TIER_FREE = 'free';
    const TIER_PREMIUM = 'premium';
    const TIER_BUSINESS = 'business';

    /**
     * Get the route key for the model.
     * Use slug instead of ID for URLs
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the store owner (user)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the polymorphic owner (User or Artist)
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all products in this store
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get only active products
     */
    public function activeProducts(): HasMany
    {
        return $this->products()->where('status', Product::STATUS_ACTIVE);
    }

    /**
     * Get all orders for this store
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all reviews for this store (polymorphic relationship)
     */
    public function reviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(StoreReview::class, 'reviewable');
    }

    /**
     * Get approved reviews only
     */
    public function approvedReviews(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->reviews()->where('is_approved', true);
    }

    /**
     * Get current subscription
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(StoreSubscription::class)
            ->where('status', 'active')
            ->latest();
    }

    /**
     * Get store categories (many-to-many)
     */
    public function categories()
    {
        return $this->belongsToMany(
            \App\Modules\Store\Models\StoreCategory::class,
            'store_category_pivot',
            'store_id',
            'category_id'
        )->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: Active stores only
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope: Artist stores only
     */
    public function scopeArtist($query)
    {
        return $query->where('store_type', self::TYPE_ARTIST);
    }

    /**
     * Scope: User stores only
     */
    public function scopeUser($query)
    {
        return $query->where('store_type', self::TYPE_USER);
    }

    /**
     * Scope: Premium stores
     */
    public function scopePremium($query)
    {
        return $query->whereIn('subscription_tier', [self::TIER_PREMIUM, self::TIER_BUSINESS]);
    }

    /**
     * Scope: Featured stores (based on sales/rating)
     */
    public function scopeFeatured($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('rating', '>=', 4.0)
            ->where('total_orders', '>=', 10)
            ->orderByDesc('total_sales_ugx');
    }

    /**
     * Scope: Search by name
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

    /**
     * Get total sales combining UGX and credits
     */
    public function getTotalSalesAttribute(): float
    {
        $ugx = $this->attributes['total_sales_ugx'] ?? 0;
        $credits = $this->attributes['total_sales_credits'] ?? 0;
        $conversionRate = config('store.currencies.credits.conversion_rate', 1);
        
        return $ugx + ($credits * $conversionRate);
    }

    /**
     * Get logo URL
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        try {
            $disk = config('store.storage.disk', 'public');
            if (!config("filesystems.disks.{$disk}")) {
                $disk = 'public';
            }
            return \Storage::disk($disk)->url($this->logo);
        } catch (\Exception $e) {
            \Log::warning("Failed to get logo URL: " . $e->getMessage());
            return \Storage::disk('public')->url($this->logo);
        }
    }

    /**
     * Get banner URL
     */
    public function getBannerUrlAttribute(): ?string
    {
        if (!$this->banner) {
            return null;
        }

        try {
            $disk = config('store.storage.disk', 'public');
            if (!config("filesystems.disks.{$disk}")) {
                $disk = 'public';
            }
            return \Storage::disk($disk)->url($this->banner);
        } catch (\Exception $e) {
            \Log::warning("Failed to get banner URL: " . $e->getMessage());
            return \Storage::disk('public')->url($this->banner);
        }
    }

    /**
     * Get store URL
     */
    public function getUrlAttribute(): string
    {
        return route('store.shop.store', $this->slug);
    }

    /**
     * Check if store is active
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if store is premium
     */
    public function getIsPremiumAttribute(): bool
    {
        return in_array($this->subscription_tier, [self::TIER_PREMIUM, self::TIER_BUSINESS]);
    }

    /**
     * Get transaction fee percentage for this store
     */
    public function getTransactionFeeAttribute(): float
    {
        return match($this->subscription_tier) {
            self::TIER_PREMIUM => config('store.fees.premium_tier', 5.0),
            self::TIER_BUSINESS => config('store.fees.business_tier', 3.0),
            default => config('store.fees.free_tier', 7.0),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Business Logic Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if store can add more products
     */
    public function canAddProducts(): bool
    {
        $limit = match($this->subscription_tier) {
            self::TIER_FREE => config('store.limits.free_tier_products', 10),
            default => -1, // Unlimited
        };

        if ($limit === -1) {
            return true;
        }

        return $this->products()->count() < $limit;
    }

    /**
     * Get remaining product slots
     */
    public function getRemainingProductSlots(): int
    {
        $limit = match($this->subscription_tier) {
            self::TIER_FREE => config('store.limits.free_tier_products', 10),
            default => -1,
        };

        if ($limit === -1) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $this->products()->count());
    }

    /**
     * Calculate platform fee for an amount
     */
    public function calculatePlatformFee(float $amount): float
    {
        // Get fee percentage based on subscription tier
        $feePercentage = match($this->subscription_tier) {
            'premium' => config('store.fees.premium_tier', 5.0),
            'business' => config('store.fees.business_tier', 3.0),
            default => config('store.fees.free_tier', 7.0),
        };
        
        $fee = $amount * ($feePercentage / 100);
        
        // Apply minimum fee
        $minFee = config('store.fees.minimum_fee', 1000);
        return max($fee, $minFee);
    }

    /**
     * Calculate promotion fee (higher rate than physical products)
     */
    public function calculatePromotionFee(float $amount): float
    {
        $feePercentage = match($this->subscription_tier) {
            'premium' => config('store.fees.promotion_premium_tier', 7.0),
            'business' => config('store.fees.promotion_business_tier', 5.0),
            default => config('store.fees.promotion_free_tier', 10.0),
        };
        
        return round($amount * ($feePercentage / 100), 2);
    }

    /**
     * Increment sales statistics
     */
    public function incrementSales(float $ugx, int $credits = 0): void
    {
        $this->increment('total_sales_ugx', $ugx);
        $this->increment('total_sales_credits', $credits);
        $this->increment('total_orders');
    }

    /**
     * Update average rating
     */
    public function updateAverageRating(): void
    {
        $average = $this->approvedReviews()->avg('rating');
        $count = $this->approvedReviews()->count();

        $this->update([
            'rating' => round($average ?? 0, 2),
            'review_count' => $count,
        ]);
    }

    /**
     * Check if subscription is active
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->subscription_tier === self::TIER_FREE) {
            return true;
        }

        return $this->subscription_expires_at && $this->subscription_expires_at->isFuture();
    }

    /**
     * Check if subscription is expiring soon (within 7 days)
     */
    public function isSubscriptionExpiringSoon(): bool
    {
        if (!$this->subscription_expires_at) {
            return false;
        }

        return $this->subscription_expires_at->diffInDays(now()) <= 7
            && $this->subscription_expires_at->isFuture();
    }

    /**
     * Activate store
     */
    public function activate(): bool
    {
        // Validate before activation
        if ($this->products()->count() === 0) {
            throw new \Exception('Store must have at least one product before activation');
        }

        if (empty($this->description)) {
            throw new \Exception('Store must have a description');
        }

        return $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Suspend store
     */
    public function suspend(string $reason = null): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata['suspension_reason'] = $reason;
        $metadata['suspended_at'] = now()->toIso8601String();

        return $this->update([
            'status' => self::STATUS_SUSPENDED,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Close store permanently
     */
    public function close(string $reason = null): bool
    {
        $metadata = $this->metadata ?? [];
        $metadata['closure_reason'] = $reason;
        $metadata['closed_at'] = now()->toIso8601String();

        return $this->update([
            'status' => self::STATUS_CLOSED,
            'metadata' => $metadata,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get default settings
     */
    public static function getDefaultSettings(): array
    {
        return [
            'theme' => [
                'primary_color' => '#3B82F6',
                'secondary_color' => '#10B981',
            ],
            'policies' => [
                'return_days' => 7,
                'shipping_note' => null,
            ],
            'notifications' => [
                'email_on_order' => true,
                'sms_on_order' => true,
            ],
        ];
    }

    /**
     * Get total revenue for this store
     */
    public function getTotalRevenue(): float
    {
        return $this->total_revenue ?? 0.0;
    }

    /**
     * Boot method
     */
    protected static function boot()
    {
        parent::boot();

        // Auto-generate UUID on creation
        static::creating(function ($store) {
            if (!$store->uuid) {
                $store->uuid = \Str::uuid();
            }
            
            // Auto-generate slug from name
            if ($store->name) {
                $baseSlug = \Str::slug($store->name);
                $slug = $baseSlug;
                $counter = 1;
                
                // Ensure slug is unique (skip current record if updating)
                while (static::where('slug', $slug)->where('id', '!=', $store->id ?? 0)->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                
                $store->slug = $slug;
            }
            
            // Set default settings
            if (!$store->settings) {
                $store->settings = self::getDefaultSettings();
            }
        });
    }
}
