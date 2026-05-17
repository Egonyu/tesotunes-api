<?php

namespace App\Modules\Promotions\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PromotionOpportunity extends Model
{
    use SoftDeletes;

    protected $table = 'promotion_opportunities';

    protected $fillable = [
        'created_by_user_id',
        'promotable_type',
        'promotable_id',
        'title',
        'brief',
        'target_platforms',
        'target_audience_niches',
        'target_regions',
        'budget_min_ugx',
        'budget_max_ugx',
        'budget_credits',
        'deadline_at',
        'deliverables',
        'metadata',
    ];

    // NOT in $fillable — managed by platform/state machine only:
    // status, slug, uuid, awarded_application_id, awarded_at,
    // view_count, application_count

    protected function casts(): array
    {
        return [
            'target_platforms' => 'array',
            'target_audience_niches' => 'array',
            'target_regions' => 'array',
            'deliverables' => 'array',
            'metadata' => 'array',
            'budget_min_ugx' => 'decimal:2',
            'budget_max_ugx' => 'decimal:2',
            'budget_credits' => 'integer',
            'view_count' => 'integer',
            'application_count' => 'integer',
            'deadline_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    const STATUS_DRAFT = 'draft';

    const STATUS_OPEN = 'open';

    const STATUS_REVIEWING = 'reviewing';

    const STATUS_AWARDED = 'awarded';

    const STATUS_CLOSED = 'closed';

    const STATUS_CANCELLED = 'cancelled';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $opp): void {
            if (! $opp->uuid) {
                $opp->uuid = Str::uuid()->toString();
            }

            if (! $opp->slug) {
                $base = Str::slug($opp->title ?? 'opportunity');
                $slug = $base;
                $suffix = 1;

                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$suffix++;
                }

                $opp->slug = $slug;
            }

            if (! $opp->status) {
                $opp->status = self::STATUS_OPEN;
            }
        });

        static::created(function (self $opp): void {
            // Denormalize opportunity count on the promotable (song/album)
            if ($opp->promotable_type && $opp->promotable_id) {
                $opp->promotable?->increment('active_opportunity_count');
            }
        });

        static::deleted(function (self $opp): void {
            if (in_array($opp->status, [self::STATUS_OPEN, self::STATUS_REVIEWING])
                && $opp->promotable_type
                && $opp->promotable_id) {
                $opp->promotable?->decrement('active_opportunity_count');
            }
        });
    }

    // --- Relationships ---

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function promotable(): MorphTo
    {
        return $this->morphTo();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PromotionApplication::class, 'opportunity_id');
    }

    public function awardedApplication(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'awarded_application_id');
    }

    // --- Scopes ---

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForPromoter($query, PromoterProfile $profile)
    {
        $niches = $profile->niches ?? [];
        $platforms = $profile->platforms ?? [];
        $regions = $profile->audience_regions ?? [];

        return $query->where(function ($q) use ($niches, $platforms, $regions) {
            foreach ($niches as $niche) {
                $q->orWhereJsonContains('target_audience_niches', $niche);
            }

            foreach ($platforms as $platform) {
                $q->orWhereJsonContains('target_platforms', $platform);
            }

            foreach ($regions as $region) {
                $q->orWhereJsonContains('target_regions', $region);
            }
        });
    }

    // --- State machine ---

    public function canTransitionTo(string $status): bool
    {
        $allowed = match ($this->status) {
            self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
            self::STATUS_OPEN => [self::STATUS_REVIEWING, self::STATUS_CLOSED, self::STATUS_CANCELLED],
            self::STATUS_REVIEWING => [self::STATUS_AWARDED, self::STATUS_OPEN, self::STATUS_CANCELLED],
            self::STATUS_AWARDED => [self::STATUS_CLOSED],
            default => [],
        };

        return in_array($status, $allowed);
    }

    public function transitionTo(string $status): bool
    {
        if (! $this->canTransitionTo($status)) {
            return false;
        }

        $data = ['status' => $status];

        if ($status === self::STATUS_CLOSED || $status === self::STATUS_CANCELLED) {
            if (in_array($this->status, [self::STATUS_OPEN, self::STATUS_REVIEWING])
                && $this->promotable_type
                && $this->promotable_id) {
                $this->promotable?->decrement('active_opportunity_count');
            }
        }

        $this->forceFill($data)->save();

        return true;
    }

    public function award(PromotionApplication $application): bool
    {
        if (! $this->canTransitionTo(self::STATUS_AWARDED)) {
            return false;
        }

        $this->forceFill([
            'status' => self::STATUS_AWARDED,
            'awarded_application_id' => $application->id,
            'awarded_at' => now(),
        ])->save();

        if ($this->promotable_type && $this->promotable_id) {
            $this->promotable?->decrement('active_opportunity_count');
            $this->promotable?->increment('total_promotions_count');
        }

        return true;
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }
}
