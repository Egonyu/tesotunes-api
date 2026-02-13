<?php

namespace App\Modules\Podcast\Models;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PodcastSubscription extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'podcast_id',
        'user_id',
        'type',
        'status',
        'price_paid',
        'payment_method',
        'transaction_id',
        'subscribed_at',
        'expires_at',
        'cancelled_at',
        'renewed_at',
        'auto_renew',
        'next_billing_date',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'renewed_at' => 'datetime',
        'next_billing_date' => 'datetime',
        'price_paid' => 'decimal:2',
        'auto_renew' => 'boolean',
    ];

    // Relationships
    public function podcast(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Podcast\Models\Podcast::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePremium($query)
    {
        return $query->where('type', 'premium');
    }

    public function scopeFree($query)
    {
        return $query->where('type', 'free');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->where('type', 'free')
                  ->orWhere(function ($q2) {
                      $q2->where('type', 'premium')
                         ->where(function ($q3) {
                             $q3->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                         });
                  });
            });
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->where('type', 'premium')
                  ->whereNotNull('expires_at')
                  ->where('expires_at', '<=', now());
            });
    }

    // Helper Methods
    public function isPremium(): bool
    {
        return $this->type === 'premium';
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->type === 'free') {
            return true;
        }

        // For premium, check expiration
        if ($this->expires_at === null) {
            return true; // Lifetime premium
        }

        return $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->type === 'premium' 
            && $this->expires_at !== null 
            && $this->expires_at->isPast();
    }

    public function cancel(): void
    {
        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);
    }

    public function renew(int $months = 1, float $price = null): void
    {
        $expiresAt = $this->expires_at && $this->expires_at->isFuture()
            ? $this->expires_at
            : now();

        $this->update([
            'type' => 'premium',
            'status' => 'active',
            'expires_at' => $expiresAt->addMonths($months),
            'renewed_at' => now(),
            'price_paid' => $price ?? $this->price_paid,
            'next_billing_date' => $this->auto_renew ? $expiresAt->addMonths($months) : null,
        ]);
    }
}
