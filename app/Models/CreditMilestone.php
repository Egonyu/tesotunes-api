<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rung on the credit goal ladder.
 *
 * Operator-editable, like referral_milestones and credit_rates, so what a goal
 * pays can be tuned without a deploy — and a goal only appears to members when
 * a row says the platform will honour it.
 */
class CreditMilestone extends Model
{
    public const REWARD_CREDITS = 'credits';

    public const TIER_BRONZE = 'bronze';

    public const TIER_SILVER = 'silver';

    public const TIER_GOLD = 'gold';

    public const TIER_PLATINUM = 'platinum';

    public const TIER_DIAMOND = 'diamond';

    protected $fillable = [
        'key',
        'name',
        'description',
        'credits_required',
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
            'credits_required' => 'integer',
            'reward_value' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function claims(): HasMany
    {
        return $this->hasMany(CreditMilestoneClaim::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
