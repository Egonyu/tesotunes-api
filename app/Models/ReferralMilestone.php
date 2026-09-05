<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rung on the referral ladder.
 *
 * Operator-editable so the growth programme can be tuned without a deploy,
 * the same way credit_rates governs what an activity pays.
 */
class ReferralMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'referrals_required',
        'reward_type',
        'reward_value',
        'badge_name',
        'badge_icon',
        'badge_tier',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'referrals_required' => 'integer',
            'reward_value' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public const REWARD_CREDITS = 'credits';

    public const TIER_BRONZE = 'bronze';

    public const TIER_SILVER = 'silver';

    public const TIER_GOLD = 'gold';

    public const TIER_PLATINUM = 'platinum';

    public const TIER_DIAMOND = 'diamond';

    public function claims(): HasMany
    {
        return $this->hasMany(ReferralMilestoneClaim::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
