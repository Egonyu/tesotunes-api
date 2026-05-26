<?php

namespace App\Models;

use App\Enums\AdPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdPlacementConfig extends Model
{
    // Keyed by placement_key string — no integer PK used as FK here
    protected $primaryKey = 'id';

    protected $fillable = [
        'placement_key',
        'label',
        'description',
        'device_type',
        'allowed_formats',
        'dimensions_width',
        'dimensions_height',
        'is_enabled',
        'target_tiers',
        'frequency_cap_per_day',
        'max_ads_per_page',
        'notes',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'allowed_formats' => 'array',
        'target_tiers' => 'array',
        'dimensions_width' => 'integer',
        'dimensions_height' => 'integer',
        'frequency_cap_per_day' => 'integer',
        'max_ads_per_page' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /** All assignments (active or not) for this placement zone. */
    public function assignments(): HasMany
    {
        return $this->hasMany(AdPlacementAssignment::class, 'placement_key', 'placement_key');
    }

    /** Admin who last updated this zone's config. */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForDevice(Builder $query, string $device): Builder
    {
        return $query->where(function (Builder $q) use ($device) {
            $q->where('device_type', 'all')->orWhere('device_type', $device);
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function placementEnum(): ?AdPlacement
    {
        return AdPlacement::tryFrom($this->placement_key);
    }

    /**
     * Retrieve the set of active, eligible ads for this zone, applying ad-level
     * targeting filters (tier, device, schedule). Returns a Builder so callers
     * can further constrain (e.g. forCountry) before fetching.
     */
    public function eligibleAds(string $tier = 'free', string $device = 'desktop'): Builder
    {
        $adIds = $this->assignments()
            ->active()
            ->orderByDesc('priority')
            ->pluck('ad_id');

        return Ad::whereIn('id', $adIds)
            ->active()
            ->forTier($tier)
            ->forDevice($device)
            ->withinTotalBudget()
            ->withinDailyBudget();
    }
}
