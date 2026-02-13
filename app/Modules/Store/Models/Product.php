<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, BelongsToMany};
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product Model
 *
 * Represents items for sale in a store
 * Supports dual currency pricing (UGX + Credits)
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'store_products';

    protected $fillable = [
        'uuid',
        'store_id',
        'category_id',
        'name',
        'slug',
        'sku',
        'description',
        'short_description',
        'images',
        'featured_image',
        'product_type',
        'status',
        'is_featured',
        'is_active',
        'is_taxable',
        'has_variants',
        'published_at',
        'view_count',
        // Promotion/service specific fields
        'metadata',
        'price_ugx',
        'price_credits',
        'allow_credit_payment',
        'allow_hybrid_payment',
        'accepts_credits', // For test compatibility and direct updates
        // Convenience column (synced with inventory)
        'stock_quantity',
        'inventory_quantity',
        'track_inventory',
        'low_stock_threshold', // For stock tracking
        // Rating/review stats
        'average_rating',
        'review_count',
    ];

    protected $casts = [
        'images' => 'array',
        'metadata' => 'array',
        'is_featured' => 'boolean',
        'is_taxable' => 'boolean',
        'has_variants' => 'boolean',
        'allow_credit_payment' => 'boolean',
        'allow_hybrid_payment' => 'boolean',
        'price_ugx' => 'decimal:2',
        'price_credits' => 'integer',
        'published_at' => 'datetime',
        'view_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Product type constants
    const TYPE_PHYSICAL = 'physical';
    const TYPE_DIGITAL = 'digital';
    const TYPE_SERVICE = 'service';
    const TYPE_EXPERIENCE = 'experience';
    const TYPE_TICKET = 'ticket';
    const TYPE_PROMOTION = 'promotion';

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_ARCHIVED = 'archived';
    const STATUS_OUT_OF_STOCK = 'out_of_stock';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    // NEW: Normalized relationships
    public function pricing()
    {
        return $this->hasOne(ProductPricing::class);
    }

    public function inventory()
    {
        return $this->hasOne(ProductInventory::class);
    }

    public function physicalSpecs()
    {
        return $this->hasOne(ProductPhysicalSpecs::class);
    }

    public function seo()
    {
        return $this->hasOne(ProductSeo::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function digitalAssets(): HasMany
    {
        return $this->hasMany(ProductDigitalAsset::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StoreReview::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', 'approved');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('track_inventory', false)
              ->orWhere('inventory_quantity', '>', 0)
              ->orWhere('allow_backorder', true);
        });
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function scopePhysical($query)
    {
        return $query->where('product_type', self::TYPE_PHYSICAL);
    }

    public function scopeDigital($query)
    {
        return $query->where('product_type', self::TYPE_DIGITAL);
    }

    public function scopeExperience($query)
    {
        return $query->where('product_type', self::TYPE_EXPERIENCE);
    }

    public function scopePromotion($query)
    {
        return $query->where('product_type', self::TYPE_PROMOTION);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->whereFullText(['name', 'description', 'short_description'], $term);
    }

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopePriceRange($query, float $min, float $max)
    {
        return $query->whereBetween('price_ugx', [$min, $max]);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

    public function getFeaturedImageUrlAttribute(): ?string
    {
        if (!$this->featured_image) {
            return null;
        }

        try {
            $disk = config('store.storage.disk', 'public');
            
            // Check if disk exists and is configured
            if (!config("filesystems.disks.{$disk}")) {
                $disk = 'public';
            }
            
            return \Storage::disk($disk)->url($this->featured_image);
        } catch (\Exception $e) {
            \Log::warning("Failed to get featured image URL: " . $e->getMessage());
            // Fallback to public disk
            return \Storage::disk('public')->url($this->featured_image);
        }
    }

    public function getImageUrlsAttribute(): array
    {
        if (!$this->images) {
            return [];
        }

        try {
            $disk = config('store.storage.disk', 'public');
            
            // Check if disk exists and is configured
            if (!config("filesystems.disks.{$disk}")) {
                $disk = 'public';
            }
            
            return array_map(function ($image) use ($disk) {
                return \Storage::disk($disk)->url($image);
            }, $this->images);
        } catch (\Exception $e) {
            \Log::warning("Failed to get image URLs: " . $e->getMessage());
            // Fallback to public disk
            return array_map(function ($image) {
                return \Storage::disk('public')->url($image);
            }, $this->images);
        }
    }

    public function getDigitalFileUrlAttribute(): ?string
    {
        if (!$this->is_digital || !$this->digital_file_path) {
            return null;
        }

        try {
            $disk = config('store.storage.disk', 'public');
            
            // Check if disk exists and is configured
            if (!config("filesystems.disks.{$disk}")) {
                $disk = 'public';
            }
            
            // Return signed URL for security (expires in 15 minutes)
            return \Storage::disk($disk)->temporaryUrl($this->digital_file_path, now()->addMinutes(15));
        } catch (\Exception $e) {
            \Log::warning("Failed to get digital file URL: " . $e->getMessage());
            // Fallback to public disk
            return \Storage::disk('public')->temporaryUrl($this->digital_file_path, now()->addMinutes(15));
        }
    }

    public function getProductUrlAttribute(): string
    {
        return route('store.shop.product', $this->uuid);
    }

    public function getIsOnSaleAttribute(): bool
    {
        $pricing = $this->pricing;
        return $pricing && $pricing->compare_at_price_ugx > 0
            && $pricing->compare_at_price_ugx > $pricing->price_ugx;
    }

    public function getDiscountPercentageAttribute(): int
    {
        if (!$this->is_on_sale) {
            return 0;
        }

        $pricing = $this->pricing;
        if (!$pricing) {
            return 0;
        }

        return round((($pricing->compare_at_price_ugx - $pricing->price_ugx) / $pricing->compare_at_price_ugx) * 100);
    }

    public function getIsInStockAttribute(): bool
    {
        $inventory = $this->inventory;
        if (!$inventory || $inventory->track_inventory !== 'track') {
            return true;
        }

        return $inventory->stock_quantity > 0 || $inventory->allow_backorder;
    }

    public function getStockStatusAttribute(): string
    {
        $inventory = $this->inventory;
        if (!$inventory || $inventory->track_inventory !== 'track') {
            return 'available';
        }

        if ($inventory->stock_quantity === 0) {
            return $inventory->allow_backorder ? 'backorder' : 'out_of_stock';
        }

        if ($inventory->stock_quantity <= 5) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Retrieve the model for a bound value.
     * Supports both slug and ID for route binding
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }
        
        return $this->where('slug', $value)->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Normalized Accessors (backward compatibility)
    |--------------------------------------------------------------------------
    */

    // Pricing accessors - check direct column first, then fall back to pricing relationship
    public function getPriceUgxAttribute()
    {
        // Direct column for promotions/services takes priority
        if (isset($this->attributes['price_ugx']) && $this->attributes['price_ugx'] !== null) {
            return $this->attributes['price_ugx'];
        }
        return $this->pricing ? $this->pricing->price_ugx : null;
    }

    public function getPriceCreditsAttribute()
    {
        // Direct column for promotions/services takes priority
        if (isset($this->attributes['price_credits']) && $this->attributes['price_credits'] !== null) {
            return $this->attributes['price_credits'];
        }
        return $this->pricing ? $this->pricing->price_credits : null;
    }

    public function getCompareAtPriceUgxAttribute()
    {
        return $this->pricing ? $this->pricing->compare_at_price_ugx : null;
    }

    public function getCompareAtPriceCreditsAttribute()
    {
        return $this->pricing ? $this->pricing->compare_at_price_credits : null;
    }

    public function getAllowCreditPaymentAttribute()
    {
        // Direct column for promotions/services takes priority
        if (isset($this->attributes['allow_credit_payment'])) {
            return (bool)$this->attributes['allow_credit_payment'];
        }
        return $this->pricing ? $this->pricing->accepts_credits : false;
    }

    public function getAllowHybridPaymentAttribute()
    {
        // Direct column for promotions/services takes priority
        if (isset($this->attributes['allow_hybrid_payment'])) {
            return (bool)$this->attributes['allow_hybrid_payment'];
        }
        return $this->pricing ? $this->pricing->allow_hybrid_payment : false;
    }

    /**
     * Accessor for accepts_credits - checks direct attribute first, then pricing relation
     */
    public function getAcceptsCreditsAttribute()
    {
        // Direct attribute takes priority (for tests and direct updates)
        if (array_key_exists('accepts_credits', $this->attributes)) {
            return (bool)$this->attributes['accepts_credits'];
        }
        return $this->pricing ? $this->pricing->accepts_credits : false;
    }

    // Inventory accessors
    public function getStockQuantityAttribute()
    {
        // Try relation first if it's loaded, then check direct attribute
        if ($this->relationLoaded('inventory') && $this->inventory) {
            return (int)$this->inventory->stock_quantity;
        }
        // Direct attribute as fallback (for convenience column)
        if (array_key_exists('stock_quantity', $this->attributes) && $this->attributes['stock_quantity'] !== null && $this->attributes['stock_quantity'] > 0) {
            return (int)$this->attributes['stock_quantity'];
        }
        // Load from relation
        return $this->inventory ? $this->inventory->stock_quantity : 0;
    }

    public function getInventoryQuantityAttribute()
    {
        // Use stock_quantity getter which handles relation properly
        return $this->stock_quantity;
    }

    public function getTrackInventoryAttribute()
    {
        // Try relation first if it's loaded
        if ($this->relationLoaded('inventory') && $this->inventory) {
            return $this->inventory->track_inventory === 'track';
        }
        // Direct attribute as fallback
        if (array_key_exists('track_inventory', $this->attributes)) {
            return (bool)$this->attributes['track_inventory'];
        }
        return $this->inventory ? ($this->inventory->track_inventory === 'track') : false;
    }

    public function getAllowBackorderAttribute()
    {
        return $this->inventory ? $this->inventory->allow_backorder : false;
    }

    // Physical specs accessors
    public function getRequiresShippingAttribute()
    {
        return $this->physicalSpecs ? $this->physicalSpecs->requires_shipping : false;
    }

    public function getWeightAttribute()
    {
        return $this->physicalSpecs ? $this->physicalSpecs->weight : null;
    }

    public function getDimensionsAttribute()
    {
        if (!$this->physicalSpecs) {
            return null;
        }
        return [
            'length' => $this->physicalSpecs->length,
            'width' => $this->physicalSpecs->width,
            'height' => $this->physicalSpecs->height,
        ];
    }

    // SEO accessors
    public function getMetaTitleAttribute()
    {
        return $this->seo ? $this->seo->meta_title : $this->name;
    }

    public function getMetaDescriptionAttribute()
    {
        return $this->seo ? $this->seo->meta_description : $this->short_description;
    }

    /*
    |--------------------------------------------------------------------------
    | Business Logic Methods
    |--------------------------------------------------------------------------
    */

    public function getPriceInCurrency(string $currency): float|int
    {
        return match($currency) {
            'ugx' => $this->price_ugx,
            'credits' => $this->price_credits ?? 0,
            default => $this->price_ugx,
        };
    }

    public function canPurchaseWithCredits(): bool
    {
        return $this->allow_credit_payment && $this->price_credits > 0;
    }

    public function canPurchaseWithHybrid(): bool
    {
        return $this->allow_hybrid_payment
            && $this->allow_credit_payment
            && $this->price_credits > 0;
    }

    public function calculateHybridPayment(int $availableCredits): array
    {
        if (!$this->canPurchaseWithHybrid()) {
            return [
                'credits' => 0,
                'ugx' => $this->price_ugx,
            ];
        }

        $maxCreditsAllowed = floor($this->price_ugx * (config('store.currencies.credits.max_credits_per_order_percentage', 50) / 100));
        $creditsToUse = min($availableCredits, $maxCreditsAllowed, $this->price_credits ?? PHP_INT_MAX);
        $ugxToPay = max(0, $this->price_ugx - $creditsToUse);

        return [
            'credits' => $creditsToUse,
            'ugx' => $ugxToPay,
        ];
    }

    public function decrementInventory(int $quantity): bool
    {
        $inventory = $this->inventory;
        if (!$inventory || $inventory->track_inventory !== 'track') {
            return true;
        }

        if ($inventory->stock_quantity < $quantity && !$inventory->allow_backorder) {
            throw new \Exception('Insufficient inventory');
        }

        $inventory->stock_quantity -= $quantity;
        $inventory->updateAvailableQuantity();
        return true;
    }

    public function incrementInventory(int $quantity): bool
    {
        $inventory = $this->inventory;
        if (!$inventory || $inventory->track_inventory !== 'track') {
            return true;
        }

        $inventory->stock_quantity += $quantity;
        $inventory->updateAvailableQuantity();
        return true;
    }

    public function requiresStock(): bool
    {
        $inventory = $this->inventory;
        return $inventory && $inventory->track_inventory === 'track';
    }

    public function isInStock(): bool
    {
        return $this->is_in_stock;
    }

    public function hasMultiplePaymentOptions(): bool
    {
        $pricing = $this->pricing;
        return $pricing && $pricing->price_ugx > 0 && $pricing->price_credits > 0;
    }

    public function incrementSales(int $quantity = 1): void
    {
        // Store statistics would be updated here in a real implementation
        // For now, we don't have a total_sales column
    }

    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    public function updateAverageRating(): void
    {
        $average = $this->approvedReviews()->avg('rating');
        $count = $this->approvedReviews()->count();

        $this->update([
            'average_rating' => round($average ?? 0, 2),
            'review_count' => $count,
        ]);
    }

    /**
     * Alias for updateAverageRating for consistency
     */
    public function updateRating(): void
    {
        $this->updateAverageRating();
    }

    public function canBeActivated(): bool
    {
        // Must have name
        if (empty($this->name)) {
            return false;
        }

        // Must have price
        if ($this->price_ugx <= 0) {
            return false;
        }

        // Physical products must have weight
        if ($this->requires_shipping && $this->product_type === self::TYPE_PHYSICAL && !$this->weight) {
            return false;
        }

        // Digital products must have file
        if ($this->is_digital && !$this->digital_file_path) {
            return false;
        }

        return true;
    }

    public function activate(): bool
    {
        if (!$this->canBeActivated()) {
            throw new \Exception('Product cannot be activated. Check required fields.');
        }

        return $this->update(['status' => self::STATUS_ACTIVE]);
    }
    
    public function isLowStock(): bool
    {
        if (!$this->track_inventory) {
            return false;
        }
        
        $threshold = $this->low_stock_threshold ?? 5;
        return $this->stock_quantity <= $threshold && $this->stock_quantity > 0;
    }
    
    public function isDigital(): bool
    {
        return $this->type === 'digital' || $this->product_type === 'digital' || $this->is_digital;
    }
    
    public function isService(): bool
    {
        return $this->type === 'service' || $this->product_type === 'service';
    }
    // Backward compatibility accessors/mutators for tests
    public function getTypeAttribute()
    {
        return $this->product_type;
    }
    
    public function setTypeAttribute($value)
    {
        $this->attributes['product_type'] = $value;
    }
    
    public function getPriceAttribute()
    {
        return $this->price_ugx;
    }
    
    public function setPriceAttribute($value)
    {
        $this->attributes['price_ugx'] = $value;
    }
    
    public function getCreditPriceAttribute()
    {
        return $this->price_credits;
    }
    
    public function setCreditPriceAttribute($value)
    {
        $this->attributes['price_credits'] = $value;
    }
    
    public function getPriceDisplayAttribute()
    {
        return $this->price_ugx / 100;
    }
    
    public function getCompareAtPriceAttribute()
    {
        return $this->compare_at_price_ugx;
    }
    
    public function setCompareAtPriceAttribute($value)
    {
        $this->attributes['compare_at_price_ugx'] = $value;
    }
    
    public function getVariantMetadataAttribute()
    {
        return $this->metadata['variants'] ?? [];
    }
    
    public function setVariantMetadataAttribute($value)
    {
        $metadata = $this->metadata ?? [];
        $metadata['variants'] = $value;
        $this->attributes['metadata'] = json_encode($metadata);
    }

    
    protected static function newFactory()
    {
        return \Database\Factories\ProductFactory::new();
    }

    /*
    |--------------------------------------------------------------------------
    | Boot Method
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (!$product->uuid) {
                $product->uuid = \Str::uuid();
            }

            if (!$product->sku) {
                $product->sku = 'SKU-' . strtoupper(\Str::random(8));
            }

            // Auto-generate slug from name if not provided
            if (!$product->slug && $product->name) {
                $baseSlug = \Str::slug($product->name);
                $slug = $baseSlug;
                $counter = 1;
                
                // Ensure slug is unique by adding counter if needed
                while (static::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                
                $product->slug = $slug;
            }
        });
    }

    /**
     * Accessor for reviews_count (alias for review_count)
     */
    public function getReviewsCountAttribute()
    {
        return $this->review_count;
    }

    // Note: average_rating column is now a real column, no accessor needed
    // The column stores the calculated average rating directly
}
