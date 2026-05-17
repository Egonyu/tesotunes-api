<?php

namespace App\Modules\Promotions\Models;

use App\Models\User;
use App\Modules\Store\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PromoterProfile extends Model
{
    use SoftDeletes;

    protected $table = 'promoter_profiles';

    protected $fillable = [
        'user_id',
        'store_id',
        'display_name',
        'slug',
        'bio',
        'platforms',
        'niches',
        'audience_regions',
        'audience_summary',
        'social_links',
        'portfolio_items',
        'proof_points',
        'campaign_highlights',
        'response_time_hours',
        'onboarded_at',
        'status',
        'metadata',
    ];

    // NOT in $fillable — managed by platform only:
    // tier, is_verified, verified_at, verified_by,
    // total_listings, total_completed_orders, average_rating, review_count

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'niches' => 'array',
            'audience_regions' => 'array',
            'social_links' => 'array',
            'portfolio_items' => 'array',
            'proof_points' => 'array',
            'campaign_highlights' => 'array',
            'metadata' => 'array',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'average_rating' => 'decimal:2',
            'response_time_hours' => 'integer',
            'total_listings' => 'integer',
            'total_completed_orders' => 'integer',
            'review_count' => 'integer',
        ];
    }

    const STATUS_ACTIVE = 'active';

    const STATUS_PAUSED = 'paused';

    const STATUS_SUSPENDED = 'suspended';

    const TIER_STARTER = 'starter';

    const TIER_RISING = 'rising';

    const TIER_ESTABLISHED = 'established';

    const TIER_ELITE = 'elite';

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $profile): void {
            if (! $profile->uuid) {
                $profile->uuid = Str::uuid()->toString();
            }

            if (! $profile->slug) {
                $base = Str::slug($profile->display_name ?? 'promoter');
                $slug = $base;
                $suffix = 1;

                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$suffix++;
                }

                $profile->slug = $slug;
            }
        });
    }

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PromotionApplication::class, 'promoter_profile_id');
    }

    public function awardedApplications(): HasMany
    {
        return $this->applications()->where('status', PromotionApplication::STATUS_AWARDED);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeByTier($query, string $tier)
    {
        return $query->where('tier', $tier);
    }

    // --- Business logic ---

    public function recalculateTier(): string
    {
        $completed = $this->total_completed_orders;
        $rating = (float) $this->average_rating;
        $tiers = config('promotions.tiers', []);

        $newTier = self::TIER_STARTER;

        foreach (array_reverse($tiers) as $tierName => $thresholds) {
            if ($completed >= $thresholds['min_completed'] && $rating >= $thresholds['min_rating']) {
                $newTier = $tierName;
                break;
            }
        }

        if ($newTier !== $this->tier) {
            $this->forceFill(['tier' => $newTier])->save();
        }

        return $newTier;
    }

    public function incrementCompletedOrders(): void
    {
        $this->increment('total_completed_orders');
        $this->recalculateTier();
    }

    public function updateAverageRating(float $newRating): void
    {
        $count = $this->review_count + 1;
        $avg = (($this->average_rating * $this->review_count) + $newRating) / $count;

        $this->forceFill([
            'average_rating' => round($avg, 2),
            'review_count' => $count,
        ])->save();

        $this->recalculateTier();
    }
}
