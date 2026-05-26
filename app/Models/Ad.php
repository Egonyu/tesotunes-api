<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Ad extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'advertiser_name',
        'type',
        'format',
        'image_url',
        'click_url',
        'cta_text',
        'html_content',
        'audio_url',
        'audio_duration_seconds',
        'native_headline',
        'native_body',
        'native_image_url',
        'adsense_slot_id',
        'adsense_format',
        'is_active',
        'starts_at',
        'ends_at',
        'total_budget_ugx',
        'daily_budget_ugx',
        'cost_per_impression_ugx',
        'cost_per_click_ugx',
        'target_tiers',
        'target_devices',
        'target_countries',
        'priority',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'target_tiers' => 'array',
        'target_devices' => 'array',
        'target_countries' => 'array',
        'total_budget_ugx' => 'decimal:2',
        'daily_budget_ugx' => 'decimal:2',
        'cost_per_impression_ugx' => 'decimal:4',
        'cost_per_click_ugx' => 'decimal:2',
        'priority' => 'integer',
        'audio_duration_seconds' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ad $ad) {
            if (! $ad->uuid) {
                $ad->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AdPlacementAssignment::class);
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Only ads that are active and within their scheduled window. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /** Filter to ads whose target_tiers include the given tier (or have no restriction). */
    public function scopeForTier(Builder $query, string $tier): Builder
    {
        return $query->where(function (Builder $q) use ($tier) {
            $q->whereNull('target_tiers')
                ->orWhereJsonContains('target_tiers', $tier);
        });
    }

    /** Filter to ads whose target_devices include the given device (or have no restriction). */
    public function scopeForDevice(Builder $query, string $device): Builder
    {
        return $query->where(function (Builder $q) use ($device) {
            $q->whereNull('target_devices')
                ->orWhereJsonContains('target_devices', $device);
        });
    }

    /** Filter to ads whose target_countries include the given ISO code (or have no restriction). */
    public function scopeForCountry(Builder $query, string $country): Builder
    {
        return $query->where(function (Builder $q) use ($country) {
            $q->whereNull('target_countries')
                ->orWhereJsonContains('target_countries', $country);
        });
    }

    /** Exclude ads whose total lifetime spend has reached or exceeded their total_budget_ugx. */
    public function scopeWithinTotalBudget(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('total_budget_ugx')
                ->orWhere(function (Builder $inner) {
                    // Cannot enforce if no cost rate is configured — pass through
                    $inner->whereNull('cost_per_impression_ugx')
                        ->whereNull('cost_per_click_ugx');
                })
                ->orWhereRaw('
                    COALESCE(cost_per_impression_ugx, 0) * (
                        SELECT COUNT(*) FROM ad_impressions
                        WHERE ad_impressions.ad_id = ads.id
                    ) + COALESCE(cost_per_click_ugx, 0) * (
                        SELECT COUNT(*) FROM ad_impressions
                        WHERE ad_impressions.ad_id = ads.id AND clicked = 1
                    ) < total_budget_ugx
                ');
        });
    }

    /** Exclude ads whose spend today has reached or exceeded their daily_budget_ugx. */
    public function scopeWithinDailyBudget(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('daily_budget_ugx')
                ->orWhere(function (Builder $inner) {
                    $inner->whereNull('cost_per_impression_ugx')
                        ->whereNull('cost_per_click_ugx');
                })
                ->orWhereRaw('
                    COALESCE(cost_per_impression_ugx, 0) * (
                        SELECT COUNT(*) FROM ad_impressions
                        WHERE ad_impressions.ad_id = ads.id
                        AND DATE(ad_impressions.created_at) = CURDATE()
                    ) + COALESCE(cost_per_click_ugx, 0) * (
                        SELECT COUNT(*) FROM ad_impressions
                        WHERE ad_impressions.ad_id = ads.id AND clicked = 1
                        AND DATE(ad_impressions.created_at) = CURDATE()
                    ) < daily_budget_ugx
                ');
        });
    }

    // ── Computed helpers ──────────────────────────────────────────────────────

    public function ctr(): float
    {
        $total = $this->impressions()->count();
        if ($total === 0) {
            return 0.0;
        }

        return round($this->impressions()->where('clicked', true)->count() / $total * 100, 2);
    }

    public function isScheduled(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $now = now();
        $afterStart = ! $this->starts_at || $this->starts_at->lte($now);
        $beforeEnd = ! $this->ends_at || $this->ends_at->gt($now);

        return $afterStart && $beforeEnd;
    }
}
