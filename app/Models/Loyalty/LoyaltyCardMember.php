<?php

namespace App\Models\Loyalty;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyCardMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'loyalty_card_id',
        'user_id',
        'tier',
        'subscription_type',
        'price_paid',
        'currency',
        'status',
        'joined_at',
        'expires_at',
        'renewed_at',
        'cancelled_at',
        'auto_renew',
        'renewal_reminder_sent',
        'payment_method',
        'payment_transaction_id',
        'total_renewals',
        'lifetime_value',
    ];

    protected $casts = [
        'price_paid' => 'decimal:2',
        'lifetime_value' => 'decimal:2',
        'joined_at' => 'datetime',
        'expires_at' => 'datetime',
        'renewed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
        'renewal_reminder_sent' => 'boolean',
        'total_renewals' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRewardRedemption::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeExpiring($query, int $days = 7)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '<=', now()->addDays($days));
    }

    public function scopeForCard($query, int $loyaltyCardId)
    {
        return $query->where('loyalty_card_id', $loyaltyCardId);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }

    public function tierLevel(): int
    {
        return config('loyalty.tier_levels.'.$this->tier, 0);
    }

    public function meetsOrExceedsTier(string $requiredTier): bool
    {
        $levels = config('loyalty.tier_levels', []);

        return ($levels[$this->tier] ?? 0) >= ($levels[$requiredTier] ?? 0);
    }

    public function tierBenefits(): array
    {
        $config = $this->loyaltyCard->tierConfig($this->tier);

        return $config['benefits'] ?? [];
    }

    public function pointsMultiplier(): float
    {
        $benefits = $this->tierBenefits();

        return (float) ($benefits['loyalty_points_multiplier'] ?? 1);
    }
}
