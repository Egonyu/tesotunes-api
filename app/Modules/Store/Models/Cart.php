<?php

namespace App\Modules\Store\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\User;

class Cart extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return CartFactory::new();
    }
    protected $fillable = [
        'uuid',
        'user_id',
        'session_id',
        'status',
        'last_activity_at',
        'expires_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($cart) {
            if (empty($cart->uuid)) {
                $cart->uuid = (string) Str::uuid();
            }
            if (empty($cart->last_activity_at)) {
                $cart->last_activity_at = now();
            }
            if (empty($cart->expires_at)) {
                $cart->expires_at = now()->addDays(30);
            }
        });
    }

    /**
     * Get the user that owns the cart
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get cart items
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Calculate total in UGX
     */
    public function getTotalUgx(): float
    {
        return $this->items->sum(function ($item) {
            return $item->getSubtotalUgx();
        });
    }

    /**
     * Calculate total in credits
     */
    public function getTotalCredits(): int
    {
        return $this->items->sum(function ($item) {
            return $item->getSubtotalCredits();
        });
    }

    /**
     * Backward compatibility accessors
     */
    public function getTotalUgxAttribute(): float
    {
        return $this->getTotalUgx();
    }

    public function getTotalCreditsAttribute(): int
    {
        return $this->getTotalCredits();
    }

    /**
     * Get item count
     */
    public function getTotalItems(): int
    {
        return $this->items->sum('quantity');
    }

    /**
     * Backward compatibility accessor
     */
    public function getItemsCountAttribute(): int
    {
        return $this->getTotalItems();
    }

    /**
     * Check if cart is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if cart is empty
     */
    public function isEmpty(): bool
    {
        return $this->items()->count() === 0;
    }

    /**
     * Mark cart as active
     */
    public function markAsActive(): void
    {
        $this->update([
            'status' => 'active',
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Mark cart as abandoned
     */
    public function markAsAbandoned(): void
    {
        $this->update(['status' => 'abandoned']);
    }

    /**
     * Mark cart as converted to order
     */
    public function markAsConverted(): void
    {
        $this->update(['status' => 'converted']);
    }

    /**
     * Update cart totals (for compatibility)
     */
    public function updateTotals(): void
    {
        // Totals are calculated dynamically via accessors
        // This method exists for compatibility
        $this->touch();
    }

    /**
     * Scope: Active carts
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Abandoned carts
     */
    public function scopeAbandoned($query)
    {
        return $query->where('status', 'abandoned')
            ->where('last_activity_at', '<', now()->subHours(24));
    }

    /**
     * Scope: Expired carts
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}
