<?php

namespace App\Modules\Store\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Payment;

/**
 * StoreSubscription Model
 * 
 * Tracks store premium subscriptions
 * Supports dual currency payment
 */
class StoreSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'payment_id',
        'plan',
        'status',
        'price_ugx',
        'price_credits',
        'paid_ugx',
        'paid_credits',
        'billing_cycle',
        'starts_at',
        'expires_at',
        'cancelled_at',
        'auto_renew',
    ];

    protected $casts = [
        'price_ugx' => 'decimal:2',
        'price_credits' => 'integer',
        'paid_ugx' => 'decimal:2',
        'paid_credits' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function getDaysRemainingAttribute(): int
    {
        if (!$this->expires_at || $this->expires_at->isPast()) {
            return 0;
        }

        return $this->expires_at->diffInDays(now());
    }
}
