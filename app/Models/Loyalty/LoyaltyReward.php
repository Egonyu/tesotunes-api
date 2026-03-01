<?php

namespace App\Models\Loyalty;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoyaltyReward extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'loyalty_card_id',
        'name',
        'description',
        'type',
        'required_tier',
        'content_type',
        'content_url',
        'product_id',
        'discount_percentage',
        'event_id',
        'experience_type',
        'points_amount',
        'is_active',
        'available_from',
        'available_until',
        'max_redemptions',
        'current_redemptions',
    ];

    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'points_amount' => 'integer',
        'is_active' => 'boolean',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'max_redemptions' => 'integer',
        'current_redemptions' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function loyaltyCard(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRewardRedemption::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTier($query, string $tier)
    {
        $levels = config('loyalty.tier_levels', []);
        $requiredLevel = $levels[$tier] ?? 0;

        // Return rewards where the required_tier is at or below the user's tier
        return $query->whereIn('required_tier', collect($levels)
            ->filter(fn ($level) => $level <= $requiredLevel)
            ->keys()
            ->toArray());
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('available_from')->orWhere('available_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('available_until')->orWhere('available_until', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_redemptions')
                    ->orWhereColumn('current_redemptions', '<', 'max_redemptions');
            });
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->available_from && $this->available_from->isFuture()) {
            return false;
        }

        if ($this->available_until && $this->available_until->isPast()) {
            return false;
        }

        if ($this->max_redemptions && $this->current_redemptions >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    public function canBeRedeemedByTier(string $userTier): bool
    {
        $levels = config('loyalty.tier_levels', []);

        return ($levels[$userTier] ?? 0) >= ($levels[$this->required_tier] ?? 0);
    }
}
