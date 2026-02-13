<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

/**
 * StoreReview Model
 * 
 * Customer reviews for stores and products
 */
class StoreReview extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'store_reviews';

    protected static function newFactory()
    {
        return \Database\Factories\Modules\Store\Models\StoreReviewFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($review) {
            // Auto-set store_id from product if not set
            if ($review->product_id && !$review->store_id) {
                $product = Product::find($review->product_id);
                if ($product) {
                    $review->store_id = $product->store_id;
                }
            }
        });

        static::created(function ($review) {
            if ($review->product_id && $review->product) {
                $review->product->updateRating();
            }
        });

        static::updated(function ($review) {
            if ($review->product_id && $review->product) {
                $review->product->updateRating();
            }
        });

        static::deleted(function ($review) {
            if ($review->product_id) {
                // Need to fetch product since model is deleted
                $product = Product::find($review->product_id);
                if ($product) {
                    $product->updateRating();
                }
            }
        });
    }

    protected $fillable = [
        'store_id',
        'order_id',
        'user_id',
        'product_id',
        'rating',
        'review',
        'title',
        'status',
        'images',
        'is_verified_purchase',
        'helpful_count',
        'not_helpful_count',
        'seller_response',
        'seller_response_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
        'helpful_count' => 'integer',
        'not_helpful_count' => 'integer',
        'seller_response_at' => 'datetime',
        'images' => 'array',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified_purchase', true);
    }

    /**
     * Accessor for owner_response (alias for seller_response)
     */
    public function getOwnerResponseAttribute()
    {
        return $this->seller_response;
    }

    /**
     * Accessor for is_approved (maps to status field)
     */
    public function getIsApprovedAttribute()
    {
        return $this->status === 'approved';
    }

    /**
     * Mutator for is_approved (maps to status field)
     */
    public function setIsApprovedAttribute($value)
    {
        $this->attributes['status'] = $value ? 'approved' : 'pending';
    }
}
